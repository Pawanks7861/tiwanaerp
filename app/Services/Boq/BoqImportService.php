<?php

namespace App\Services\Boq;

use App\Imports\BoqRowsImport;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Masters\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * BOQ Excel import. Every row is validated first; if any row fails, nothing is written and the
 * errors are reported per row. Valid files are appended to the draft BOQ in one transaction.
 */
class BoqImportService
{
    /** Template headings; their slugs are the row keys. */
    public const HEADINGS = [
        'Section Code', 'Section Name', 'Subsection Code', 'Subsection Name', 'Item Code', 'Name', 'Description',
        'HSN SAC', 'Unit', 'Measurement NOB', 'Measurement Nos', 'Measurement Length', 'Measurement Width', 'Measurement Height',
        'Measurement Mode', 'Quantity',
        'Material Rate', 'Labour Rate', 'Equipment Rate', 'Subcontract Rate',
        'Margin Percent', 'Client Rate', 'Amount', 'Remarks',
    ];

    private const REQUIRED_COLUMNS = ['name', 'unit', 'quantity'];

    private const NUMERIC_COLUMNS = [
        'quantity', 'measurement_nob', 'measurement_nos', 'measurement_length', 'measurement_width', 'measurement_height',
        'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'margin_percent',
        'client_rate', 'gst_rate', 'amount',
    ];

    /** @var list<string> */
    private const EXCEL_ERRORS = [
        '#REF!', '#VALUE!', '#DIV/0!', '#N/A', '#NAME?', '#NUM!', '#NULL!', '#GETTING_DATA', '#FORMULA!',
    ];

    /** @var list<string> */
    private const SUMMARY_PREFIXES = [
        'total', 'subtotal', 'sub total', 'grand total', 'b/f', 'c/f', 'brought forward', 'carried forward',
    ];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly BoqService $boqs) {}

    /**
     * @return int number of lines imported
     */
    public function import(Boq $boq, UploadedFile $file, bool $canEditCosts): int
    {
        $boq->assertEditable();

        try {
            $rows = Excel::toArray(new BoqRowsImport, $file)[0] ?? [];
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'The file could not be read. Upload an .xlsx, .xls or .csv file based on the sample template.']);
        }
        [$parsed, $errors] = $this->parse($rows);

        if ($errors !== []) {
            throw $this->rowErrors($errors);
        }

        $this->appendRows($boq, $parsed, $canEditCosts);

        return count($parsed);
    }

    /**
     * Append already-validated rows to an editable BOQ (used by the universal import engine).
     *
     * @param  list<array<string, mixed>>  $parsed
     */
    public function appendRows(Boq $boq, array $parsed, bool $canEditCosts): void
    {
        if ($parsed === []) {
            return;
        }

        DB::transaction(function () use ($boq, $parsed, $canEditCosts) {
            Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail()->assertEditable();

            $sections = $boq->sections()->get();
            $nextSort = (int) $boq->items()->max('sort_order');

            foreach ($parsed as $row) {
                $sectionName = $row['section_name'] ?? 'General';
                $section = $this->section($boq, $sections, $row['section_code'] ?? null, $sectionName, null);
                if (($row['subsection_name'] ?? null) !== null) {
                    $section = $this->section($boq, $sections, $row['subsection_code'] ?? null, $row['subsection_name'], $section);
                }

                $item = new BoqItem(['line_uid' => (string) Str::uuid()]);
                $this->boqs->fillLine($item, $row, $canEditCosts, collect());
                $item->boq_section_id = $section->id;
                $item->sort_order = ++$nextSort;
                if (array_key_exists('gst_rate', $row)) {
                    $item->gst_rate = $row['gst_rate'];
                }
                if (isset($row['source_type'])) {
                    $item->source_type = $row['source_type'];
                    $item->source_import_id = $row['source_import_id'] ?? null;
                    $item->source_sheet = $row['source_sheet'] ?? null;
                    $item->source_row = $row['source_row'] ?? null;
                }
                $item->boq()->associate($boq);
                $item->save();
            }

            $this->boqs->recalculateTotals($boq);
        });
    }

    /**
     * Remove items previously created by a draft import batch (manual items kept).
     */
    public function replaceImportBatch(Boq $boq, int $previousImportId): int
    {
        $boq->assertEditable();

        return DB::transaction(function () use ($boq, $previousImportId) {
            Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail()->assertEditable();

            $deleted = $boq->items()
                ->where('source_import_id', $previousImportId)
                ->delete();

            $this->boqs->recalculateTotals($boq);

            return (int) $deleted;
        });
    }

    /**
     * Validate one mapped spreadsheet row for the universal importer.
     *
     * @param  array<string, mixed>  $mapped
     * @return array{row: array<string, mixed>, warnings: list<string>}
     */
    public function validateMappedRow(array $mapped): array
    {
        [$parsed, $errors, $warnings] = $this->parse([$mapped], collectWarnings: true);
        if ($errors !== []) {
            throw ValidationException::withMessages(['row' => [$errors[0]]]);
        }
        if ($parsed === []) {
            throw ValidationException::withMessages(['row' => ['This row is empty.']]);
        }

        return ['row' => $parsed[0], 'warnings' => $warnings[0] ?? []];
    }

    /**
     * Classify a mapped row before numeric validation (blank / summary / head / item).
     *
     * @param  array<string, mixed>  $mapped
     * @return array{kind: string, head?: string, subhead?: string}
     */
    public function classifyMappedRow(array $mapped): array
    {
        $values = $this->normalise($mapped);
        if (array_filter($values, fn ($v) => $v !== null) === []) {
            return ['kind' => 'blank'];
        }

        $name = $values['name'] ?? $values['description'] ?? null;
        $hasQty = $values['quantity'] !== null && $values['quantity'] !== '';
        $hasUnit = $values['unit'] !== null && $values['unit'] !== '';
        $hasRate = $values['client_rate'] !== null && $values['client_rate'] !== '';

        if ($name !== null && $this->isSummaryLabel($name) && ! $hasQty && ! $hasUnit) {
            return ['kind' => 'summary'];
        }

        if ($name !== null && ! $hasQty && ! $hasUnit && ! $hasRate) {
            if ($this->looksLikeSubhead($name)) {
                return ['kind' => 'subhead', 'subhead' => $name];
            }

            return ['kind' => 'head', 'head' => $name];
        }

        return ['kind' => 'item'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2?: list<list<string>>}
     */
    public function parse(array $rows, bool $collectWarnings = false): array
    {
        if ($rows === []) {
            return [[], ['The file has no data rows. Use the sample template.'], []];
        }

        $firstKeys = array_keys($rows[array_key_first($rows)]);
        $missing = array_diff(self::REQUIRED_COLUMNS, $firstKeys);
        // Soften: allow missing keys in key list when values can still be null — only fail if truly absent from schema.
        if ($missing !== [] && ! array_intersect(self::REQUIRED_COLUMNS, $firstKeys)) {
            return [[], ['Missing column(s): '.implode(', ', $missing).'. Map Item Description, Unit, and Quantity.'], []];
        }

        if (count($rows) > self::MAX_ROWS) {
            return [[], ['The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.'], []];
        }

        $units = $this->unitLookup();
        $parsed = [];
        $errors = [];
        $allWarnings = [];

        foreach (array_values($rows) as $index => $raw) {
            $line = $index + 2;
            $rowWarnings = [];

            foreach (['quantity', 'client_rate', 'gst_rate', 'amount'] as $numericField) {
                if (isset($raw[$numericField]) && $this->isExcelError($raw[$numericField])) {
                    $errors[] = "Row {$line}: ".ucfirst(str_replace('_', ' ', $numericField)).' contains Excel error '.$raw[$numericField];

                    continue 2;
                }
            }

            $values = $this->normalise($raw);

            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }

            if (($values['name'] ?? null) !== null && $this->isSummaryLabel($values['name']) && $values['quantity'] === null && $values['unit'] === null) {
                continue;
            }

            if (($values['section_name'] ?? null) === null || trim((string) $values['section_name']) === '') {
                $values['section_name'] = 'General';
            }

            $validator = Validator::make($values, $this->rules());
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Row {$line}: {$message}";
                }

                continue;
            }

            $unitId = $this->resolveUnitId($units, (string) $values['unit']);
            if ($unitId === null) {
                $errors[] = "Row {$line}: Unit \"{$values['unit']}\" could not be resolved.";

                continue;
            }

            if (($values['amount'] ?? null) !== null && ($values['client_rate'] ?? null) !== null) {
                $expected = (float) $values['quantity'] * (float) $values['client_rate'];
                $imported = (float) $values['amount'];
                if (abs($expected - $imported) > 0.05) {
                    $rowWarnings[] = 'Imported Amount does not match Quantity × Rate; ERP will use Quantity × Rate.';
                }
            }

            unset($values['amount'], $values['remarks']);
            $parsed[] = [...$values, 'unit_id' => $unitId];
            $allWarnings[] = $rowWarnings;
        }

        if ($parsed === [] && $errors === []) {
            $errors[] = 'The file has no data rows.';
        }

        return $collectWarnings ? [$parsed, $errors, $allWarnings] : [$parsed, $errors];
    }

    public function isExcelError(mixed $value): bool
    {
        return is_string($value) && in_array(strtoupper(trim($value)), self::EXCEL_ERRORS, true);
    }

    public function isSummaryLabel(string $name): bool
    {
        $normalized = Str::lower(trim($name));
        foreach (self::SUMMARY_PREFIXES as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.' ') || str_starts_with($normalized, $prefix.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One error key per message ("file_rows.N"), because Inertia only forwards the first message of a key.
     *
     * @param  list<string>  $errors
     */
    private function rowErrors(array $errors): ValidationException
    {
        $messages = ['file' => count($errors) === 1 ? $errors[0] : count($errors).' problem(s) found. Nothing was imported; fix the file and upload it again.'];
        foreach (array_slice($errors, 0, 200) as $i => $message) {
            $messages["file_rows.{$i}"] = $message;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $rate = ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'];

        return [
            'section_code' => ['nullable', 'string', 'max:30'],
            'section_name' => ['required', 'string', 'max:200'],
            'subsection_code' => ['nullable', 'string', 'max:30'],
            'subsection_name' => ['nullable', 'string', 'max:200'],
            'item_code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'hsn_sac' => ['nullable', 'string', 'max:16'],
            'unit' => ['required', 'string', 'max:20'],
            'measurement_nob' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'measurement_nos' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'measurement_length' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'measurement_width' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'measurement_height' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'measurement_mode' => ['nullable', 'string', 'in:manual,auto'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'material_rate' => $rate,
            'labour_rate' => $rate,
            'equipment_rate' => $rate,
            'subcontract_rate' => $rate,
            'margin_percent' => ['nullable', 'numeric', 'min:-100', 'max:999', 'decimal:0,4'],
            'client_rate' => $rate,
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'amount' => $rate,
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Trim text, turn spreadsheet numbers into exact decimal strings and blanks into null.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string|null>
     */
    private function normalise(array $raw): array
    {
        $keys = array_unique(array_merge(
            array_map(fn ($h) => Str::slug($h, '_'), self::HEADINGS),
            array_keys($raw),
        ));

        $values = [];
        foreach ($keys as $key) {
            if (! in_array($key, array_map(fn ($h) => Str::slug($h, '_'), self::HEADINGS), true)
                && ! in_array($key, [
                    'section_code', 'section_name', 'subsection_code', 'subsection_name', 'item_code', 'name', 'description',
                    'hsn_sac', 'unit', 'measurement_nob', 'measurement_nos', 'measurement_length', 'measurement_width', 'measurement_height',
                    'measurement_mode', 'quantity', 'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate',
                    'margin_percent', 'client_rate', 'gst_rate', 'amount', 'remarks',
                ], true)) {
                continue;
            }

            $value = $raw[$key] ?? null;

            if ($this->isExcelError($value)) {
                $values[$key] = is_string($value) ? trim($value) : '#REF!';

                continue;
            }

            if (in_array($key, ['item_code', 'hsn_sac'], true)) {
                if ($value === null || $value === '') {
                    $values[$key] = null;
                } elseif (is_float($value) || is_int($value)) {
                    $values[$key] = is_float($value) && floor($value) != $value
                        ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.')
                        : (string) (int) $value;
                } else {
                    $values[$key] = trim((string) $value) ?: null;
                }

                continue;
            }

            if (in_array($key, self::NUMERIC_COLUMNS, true)) {
                $values[$key] = $this->parseNumeric($value);

                continue;
            }

            if (is_float($value)) {
                $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            } elseif (is_int($value)) {
                $value = (string) $value;
            } elseif (is_string($value)) {
                $value = trim($value);
            } elseif ($value !== null) {
                $value = trim((string) $value);
            }

            $values[$key] = $value === '' || $value === null ? null : $value;
        }

        // Fold remarks into description when description empty.
        if (($values['description'] ?? null) === null && ($values['remarks'] ?? null) !== null) {
            $values['description'] = $values['remarks'];
        }

        return $values;
    }

    private function parseNumeric(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        }

        $text = trim((string) $value);
        $text = preg_replace('/^(rs\.?|inr)\s*/iu', '', $text) ?? $text;
        $text = str_replace(['₹', 'Rs.', 'Rs', 'INR', '%'], '', $text);
        $text = str_replace(["\u{00A0}", ' '], '', $text);
        $text = str_replace(',', '', $text);

        if (! is_numeric($text)) {
            return null;
        }

        return $text;
    }

    private function looksLikeSubhead(string $name): bool
    {
        $normalized = Str::lower($name);

        return str_contains($normalized, 'subhead')
            || str_contains($normalized, 'sub-head')
            || str_contains($normalized, 'subsection')
            || str_starts_with($normalized, 'sub ');
    }

    /**
     * @return Collection<string, int> lower-cased symbol or name => unit id
     */
    private function unitLookup(): Collection
    {
        $map = collect();
        foreach (Unit::query()->active()->get(['id', 'name', 'symbol']) as $unit) {
            $map->put(Str::lower($unit->name), $unit->id);
            $map->put(Str::lower($unit->symbol), $unit->id);
        }

        return $map;
    }

    /**
     * @param  Collection<string, int>  $units
     */
    private function resolveUnitId(Collection $units, string $unit): ?int
    {
        $key = Str::lower(trim($unit));
        if ($units->has($key)) {
            return $units->get($key);
        }

        // Flexible UOM: create a company unit from the imported symbol when missing.
        $created = Unit::query()->create([
            'name' => $unit,
            'symbol' => Str::limit($unit, 20, ''),
            'decimal_places' => 3,
            'is_active' => true,
        ]);
        $units->put(Str::lower($created->name), $created->id);
        $units->put(Str::lower($created->symbol), $created->id);

        return $created->id;
    }

    /**
     * @param  Collection<int, BoqSection>  $sections
     */
    private function section(Boq $boq, Collection $sections, ?string $code, string $name, ?BoqSection $parent): BoqSection
    {
        $parentId = $parent?->id;
        $match = $sections->first(fn (BoqSection $s) => ($s->parent_id === null ? null : (int) $s->parent_id) === $parentId && (
            $code !== null ? Str::lower((string) $s->code) === Str::lower($code) : Str::lower(trim($s->name)) === Str::lower(trim($name))
        ));

        if ($match !== null) {
            return $match;
        }

        $section = new BoqSection([
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'sort_order' => $sections->where('parent_id', $parentId)->count() + 1,
        ]);
        $section->boq()->associate($boq);
        $section->save();
        $sections->push($section);

        return $section;
    }
}
