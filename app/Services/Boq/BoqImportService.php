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
        'HSN SAC', 'Unit', 'Quantity', 'Material Rate', 'Labour Rate', 'Equipment Rate', 'Subcontract Rate',
        'Margin Percent', 'Client Rate',
    ];

    private const REQUIRED_COLUMNS = ['section_name', 'name', 'unit', 'quantity'];

    private const NUMERIC_COLUMNS = [
        'quantity', 'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'margin_percent', 'client_rate',
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

        DB::transaction(function () use ($boq, $parsed, $canEditCosts) {
            Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail()->assertEditable();

            $sections = $boq->sections()->get();
            $nextSort = (int) $boq->items()->max('sort_order');

            foreach ($parsed as $row) {
                $section = $this->section($boq, $sections, $row['section_code'], $row['section_name'], null);
                if ($row['subsection_name'] !== null) {
                    $section = $this->section($boq, $sections, $row['subsection_code'], $row['subsection_name'], $section);
                }

                $item = new BoqItem(['line_uid' => (string) Str::uuid()]);
                $this->boqs->fillLine($item, $row, $canEditCosts, collect());
                $item->boq_section_id = $section->id;
                $item->sort_order = ++$nextSort;
                $item->boq()->associate($boq);
                $item->save();
            }

            $this->boqs->recalculateTotals($boq);
        });

        return count($parsed);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public function parse(array $rows): array
    {
        if ($rows === []) {
            return [[], ['The file has no data rows. Use the sample template.']];
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($rows[array_key_first($rows)]));
        if ($missing !== []) {
            return [[], ['Missing column(s): '.implode(', ', $missing).'. Use the sample template headings.']];
        }

        if (count($rows) > self::MAX_ROWS) {
            return [[], ['The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']];
        }

        $units = $this->unitLookup();
        $parsed = [];
        $errors = [];

        foreach (array_values($rows) as $index => $raw) {
            $line = $index + 2;
            $values = $this->normalise($raw);

            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }

            $validator = Validator::make($values, $this->rules());
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Row {$line}: {$message}";
                }

                continue;
            }

            $unitId = $units->get(Str::lower($values['unit']));
            if ($unitId === null) {
                $errors[] = "Row {$line}: Unit \"{$values['unit']}\" is not an active unit of this company.";

                continue;
            }

            $parsed[] = [...$values, 'unit_id' => $unitId];
        }

        if ($parsed === [] && $errors === []) {
            $errors[] = 'The file has no data rows.';
        }

        return [$parsed, $errors];
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
            'hsn_sac' => ['nullable', 'string', 'max:10'],
            'unit' => ['required', 'string', 'max:20'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'material_rate' => $rate,
            'labour_rate' => $rate,
            'equipment_rate' => $rate,
            'subcontract_rate' => $rate,
            'margin_percent' => ['nullable', 'numeric', 'min:-100', 'max:999', 'decimal:0,4'],
            'client_rate' => $rate,
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
        $values = [];
        foreach (array_map(fn ($h) => Str::slug($h, '_'), self::HEADINGS) as $key) {
            $value = $raw[$key] ?? null;

            if (is_float($value)) {
                $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            } elseif (is_int($value)) {
                $value = (string) $value;
            } elseif (is_string($value)) {
                $value = trim($value);
                if (in_array($key, self::NUMERIC_COLUMNS, true)) {
                    $value = str_replace([',', ' '], '', $value);
                }
            } elseif ($value !== null) {
                $value = trim((string) $value);
            }

            $values[$key] = $value === '' ? null : $value;
        }

        return $values;
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
     * @param  Collection<int, BoqSection>  $sections
     */
    private function section(Boq $boq, Collection $sections, ?string $code, string $name, ?BoqSection $parent): BoqSection
    {
        $parentId = $parent?->id;
        $match = $sections->first(fn (BoqSection $s) => ($s->parent_id === null ? null : (int) $s->parent_id) === $parentId && (
            $code !== null ? Str::lower((string) $s->code) === Str::lower($code) : Str::lower($s->name) === Str::lower($name)
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
