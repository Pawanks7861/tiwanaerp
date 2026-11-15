<?php

namespace App\Services\Boq;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Detect Feed-Plant-style Advanced Costing workbook sheets for staged import.
 * Does not evaluate Excel formulas or store #REF! / cell references as authority.
 *
 * Import stages: Upload → Detect → Map → Preview → Validate → Confirm → Reconcile.
 */
class AdvancedBoqWorkbookImportDetector
{
    /** @var array<string, string> sheet title fragment => ERP domain key */
    public const SHEET_ALIASES = [
        'feed plant' => 'measurement_costing',
        '5000 mt costing' => 'measurement_costing',
        "mb's" => 'measurement_book',
        'mb' => 'measurement_book',
        'sheet1' => 'rate_analysis',
        'plant steel' => 'bbs',
        '5000 mt silo steel' => 'bbs',
        'steel summary' => 'steel_summary',
        'material consumption' => 'material_consumption',
        'estimated labour cost' => 'labour_analysis',
        'sheet2' => 'rate_comparison',
        'schedule' => 'planning_reference',
        'job respons' => 'operational_docs',
    ];

    public const DOMAIN_LABELS = [
        'measurement_costing' => 'Measurement & Costing',
        'measurement_book' => 'Measurement Book',
        'rate_analysis' => 'Rate Analysis',
        'bbs' => 'BBS',
        'steel_summary' => 'Steel Summary (reconcile)',
        'material_consumption' => 'Material Consumption (reconcile)',
        'labour_analysis' => 'Labour Analysis (reconcile)',
        'rate_comparison' => 'Rate Comparison',
        'planning_reference' => 'Planning / Schedule (optional)',
        'operational_docs' => 'Operational docs (ignored)',
    ];

    /**
     * @param  list<string>  $sheetNames
     * @return array{
     *   sheets: list<array{name: string, domain: string|null, importable: bool, label: ?string}>,
     *   domains_detected: list<string>,
     *   warnings: list<string>
     * }
     */
    public function detect(array $sheetNames): array
    {
        $sheets = [];
        $domains = [];
        $warnings = [];

        foreach ($sheetNames as $name) {
            $trimmed = trim($name);
            $domain = $this->resolveDomain($trimmed);
            $importable = $domain !== null && ! in_array($domain, ['operational_docs', 'planning_reference', 'steel_summary', 'material_consumption', 'labour_analysis', 'measurement_book'], true);
            $sheets[] = [
                'name' => $trimmed,
                'domain' => $domain,
                'importable' => $importable,
                'label' => $domain ? (self::DOMAIN_LABELS[$domain] ?? $domain) : null,
            ];
            if ($domain !== null) {
                $domains[] = $domain;
            } else {
                $warnings[] = "Unmapped sheet [{$trimmed}] — map manually before confirm.";
            }
            if ($domain === 'operational_docs') {
                $warnings[] = "Sheet [{$trimmed}] is operational documentation, not BOQ costing data.";
            }
            if ($domain === 'planning_reference') {
                $warnings[] = "Sheet [{$trimmed}] is a schedule reference — Planning remains its own module (opt-in).";
            }
        }

        return [
            'sheets' => $sheets,
            'domains_detected' => array_values(array_unique($domains)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Open workbook from disk and detect sheets with row counts (read-only).
     *
     * @return array{
     *   sheets: list<array{name: string, domain: string|null, importable: bool, label: ?string, rows_detected: int, status: string}>,
     *   domains_detected: list<string>,
     *   warnings: list<string>,
     *   sheet_names: list<string>
     * }
     */
    public function inspectFile(string $absolutePath): array
    {
        $spreadsheet = $this->loadSpreadsheet($absolutePath, dataOnly: true);
        $names = $spreadsheet->getSheetNames();
        $base = $this->detect($names);

        $enriched = [];
        foreach ($base['sheets'] as $i => $sheet) {
            $worksheet = $spreadsheet->getSheet($i);
            $rows = (int) $worksheet->getHighestDataRow();
            $enriched[] = [
                ...$sheet,
                'rows_detected' => $rows,
                'status' => $sheet['domain'] === null ? 'unmapped' : ($sheet['importable'] ? 'detected' : 'reference'),
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'sheets' => $enriched,
            'domains_detected' => $base['domains_detected'],
            'warnings' => $base['warnings'],
            'sheet_names' => array_map('trim', $names),
        ];
    }

    public function resolveDomain(string $sheetName): ?string
    {
        $normalized = mb_strtolower(trim($sheetName));

        foreach (self::SHEET_ALIASES as $alias => $domain) {
            if ($normalized === $alias || str_starts_with($normalized, $alias)) {
                return $domain;
            }
        }

        return null;
    }

    public function loadSpreadsheet(string $absolutePath, bool $dataOnly = false): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly($dataOnly);

            return $reader->load($absolutePath);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => 'The workbook could not be read. Upload a valid .xlsx Advanced Costing file.',
            ]);
        }
    }
}
