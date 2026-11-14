<?php

namespace App\Services\Boq;

/**
 * Detect Feed-Plant-style Advanced Costing workbook sheets for staged import.
 * Does not evaluate Excel formulas or store #REF! / cell references.
 *
 * Import stages (UI/service pipeline): Upload → Detect → Map → Preview → Validate → Confirm.
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

    /**
     * @param  list<string>  $sheetNames
     * @return array{
     *   sheets: list<array{name: string, domain: string|null, importable: bool}>,
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
            $importable = $domain !== null && ! in_array($domain, ['operational_docs', 'planning_reference'], true);
            $sheets[] = [
                'name' => $trimmed,
                'domain' => $domain,
                'importable' => $importable,
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
                $warnings[] = "Sheet [{$trimmed}] is a schedule reference — Planning remains its own module.";
            }
        }

        return [
            'sheets' => $sheets,
            'domains_detected' => array_values(array_unique($domains)),
            'warnings' => $warnings,
        ];
    }

    public function resolveDomain(string $sheetName): ?string
    {
        $normalized = strtolower(trim($sheetName));
        $normalized = rtrim($normalized);

        foreach (self::SHEET_ALIASES as $alias => $domain) {
            if ($normalized === $alias || str_starts_with($normalized, $alias)) {
                return $domain;
            }
        }

        return null;
    }
}
