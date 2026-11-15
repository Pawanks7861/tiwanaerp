<?php

namespace App\Services\Boq;

use App\Enums\Boq\MeasurementFormulaType;
use App\Support\Math\Decimal;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parse Jaipur Feed-Plant-style Advanced Costing sheets into structured preview payloads.
 * Never treats Excel formulas / #REF! as authoritative ERP results.
 */
class AdvancedBoqWorkbookParser
{
    /** @var list<string> */
    private const EXCEL_ERRORS = [
        '#REF!', '#VALUE!', '#DIV/0!', '#N/A', '#NAME?', '#NUM!', '#NULL!', '#GETTING_DATA',
    ];

    public function __construct(private readonly AdvancedBoqWorkbookImportDetector $detector) {}

    /**
     * @return array<string, mixed>
     */
    public function parseWorkbook(string $absolutePath): array
    {
        $spreadsheet = $this->detector->loadSpreadsheet($absolutePath, dataOnly: false);
        $detection = $this->detector->detect($spreadsheet->getSheetNames());

        $costing = [];
        $rateAnalyses = [];
        $bbs = [];
        $rateComparison = [];
        $reconcile = [
            'steel_summary' => [],
            'material_consumption' => [],
            'labour_analysis' => [],
        ];
        $schedule = [];
        $warnings = $detection['warnings'];
        $errors = [];

        foreach ($spreadsheet->getSheetNames() as $index => $rawName) {
            $name = trim($rawName);
            $domain = $this->detector->resolveDomain($name);
            $sheet = $spreadsheet->getSheet($index);

            try {
                match ($domain) {
                    'measurement_costing' => $costing[] = $this->parseMeasurementCosting($sheet, $name),
                    'rate_analysis' => $rateAnalyses = array_merge($rateAnalyses, $this->parseRateAnalysis($sheet, $name)),
                    'bbs' => $bbs[] = $this->parseBbs($sheet, $name),
                    'rate_comparison' => $rateComparison = $this->parseRateComparison($sheet, $name),
                    'steel_summary' => $reconcile['steel_summary'] = $this->parseSteelSummary($sheet, $name, $warnings),
                    'material_consumption' => $reconcile['material_consumption'] = $this->parseMaterialConsumption($sheet, $name, $warnings),
                    'labour_analysis' => $reconcile['labour_analysis'] = $this->parseLabourSummary($sheet, $name, $warnings),
                    'planning_reference' => $schedule = $this->parseSchedule($sheet, $name),
                    'measurement_book' => null, // validation source only for now
                    'operational_docs' => null,
                    default => null,
                };
            } catch (\Throwable $e) {
                report($e);
                $errors[] = [
                    'code' => 'SHEET_PARSE_FAILED',
                    'sheet' => $name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        $steelWastage = (string) ($reconcile['steel_summary']['wastage_percent'] ?? '5');
        foreach ($bbs as $i => $block) {
            $bbs[$i]['wastage_percent'] = $steelWastage;
        }

        return [
            'detection' => $detection,
            'costing_sections' => $costing,
            'rate_analyses' => $rateAnalyses,
            'bbs' => $bbs,
            'rate_comparison' => $rateComparison,
            'reconcile' => $reconcile,
            'schedule' => $schedule,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{sheet: string, root_section: string, items: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parseMeasurementCosting(Worksheet $sheet, string $sheetName): array
    {
        $root = str_contains(mb_strtolower($sheetName), '5000') ? '5000 MT Silo' : 'Feed Plant';
        $items = [];
        $warnings = [];
        $currentWorkHead = null;
        $highest = (int) $sheet->getHighestDataRow();

        for ($r = 1; $r <= $highest; $r++) {
            $a = $this->cellString($sheet, 'A', $r);
            $b = $this->cellString($sheet, 'B', $r);
            $c = $this->cellString($sheet, 'C', $r);
            if ($b === '') {
                continue;
            }

            $bNorm = mb_strtolower(trim($b));
            if (in_array($bNorm, ['total', 'description'], true) || str_starts_with($bNorm, 'sr')) {
                continue;
            }

            // Section / work-head title rows (have serial or bold description without unit)
            $unit = $this->normalizeUnit($c);
            $nos = $this->cellNumeric($sheet, 'D', $r, $warnings, $sheetName);
            $length = $this->cellNumeric($sheet, 'E', $r, $warnings, $sheetName);
            $width = $this->cellNumeric($sheet, 'F', $r, $warnings, $sheetName);
            $height = $this->cellNumeric($sheet, 'G', $r, $warnings, $sheetName);

            $isHeader = $unit === null && $nos === null && $length === null && $width === null && $height === null;
            if ($isHeader) {
                if ($a !== '' || ! in_array($bNorm, [mb_strtolower($root), 'feed plant', '5000 mt silo'], true)) {
                    if (! in_array($bNorm, [mb_strtolower($root), 'feed plant', '5000 mt silo'], true)) {
                        $currentWorkHead = $b;
                    }
                }

                continue;
            }

            if ($unit === null) {
                continue;
            }

            $excelQty = $this->cellNumeric($sheet, 'H', $r, $warnings, $sheetName);

            // Skip rolled-up / formula spill rows that reuse prior totals as dimensions.
            if ($length !== null && Decimal::of($length)->greaterThan('10000')) {
                $warnings[] = "Unsupported row {$r} on [{$sheetName}] — dimension overflow (likely summary spill).";

                continue;
            }

            // Shuttering-style: Nos × Length × Height with empty Width → treat Height as Width for Area.
            if ($width === null && $length !== null && $height !== null) {
                $width = $height;
                $height = null;
            }
            $formulaType = $this->inferFormulaType($nos, $length, $width, $height, $unit);
            $erpQty = null;
            $qtyWarning = null;

            if ($formulaType !== null) {
                $calc = MeasurementCalculator::line([
                    'formula_type' => $formulaType->value,
                    'nos' => $nos ?? '1',
                    'length' => $length,
                    'width' => $width,
                    'height_depth' => $height,
                ]);
                $erpQty = $calc['calculated_quantity'];
                if ($excelQty !== null && ! Decimal::of($erpQty)->equals(Decimal::of($excelQty))) {
                    $diff = Decimal::of($erpQty)->minus($excelQty)->abs();
                    if ($diff->greaterThan('0.01')) {
                        $qtyWarning = "Qty mismatch row {$r}: Excel {$excelQty} vs ERP {$erpQty}";
                        $warnings[] = $qtyWarning;
                    }
                }
            } elseif ($excelQty !== null) {
                $formulaType = MeasurementFormulaType::Manual;
                $erpQty = Decimal::of($excelQty)->toQuantity();
            } else {
                $warnings[] = "Skipped row {$r} on [{$sheetName}] — no measurable dimensions.";

                continue;
            }

            // Cost-summary spill rows reuse rates/totals as Nos×L×W and explode to absurd quantities.
            $summaryNames = [
                'cement', 'sand', 'aggregate', 'steel', 'dressing', 'pcc', 'rcc (m-25)', 'rcc',
                'shuttering', 'anchor bolt', 'excavation work', 'piling work',
            ];
            if (Decimal::of($erpQty)->greaterThan('50000') || in_array($bNorm, $summaryNames, true)) {
                // Keep legitimate large excavations (e.g. 2834 Cum); only drop summary labels / extreme spills.
                if (in_array($bNorm, $summaryNames, true) || Decimal::of($erpQty)->greaterThan('100000')) {
                    $warnings[] = "Unsupported row {$r} on [{$sheetName}] — cost-summary spill [{$b}] erp={$erpQty}.";

                    continue;
                }
            }

            $cement = $this->cellNumeric($sheet, 'I', $r, $warnings, $sheetName);
            $sand = $this->cellNumeric($sheet, 'J', $r, $warnings, $sheetName);
            $agg = $this->cellNumeric($sheet, 'K', $r, $warnings, $sheetName);
            $cementRate = $this->cellNumeric($sheet, 'O', $r, $warnings, $sheetName);
            $sandRate = $this->cellNumeric($sheet, 'P', $r, $warnings, $sheetName);
            $aggRate = $this->cellNumeric($sheet, 'Q', $r, $warnings, $sheetName);
            $labourRate = $this->cellNumeric($sheet, 'U', $r, $warnings, $sheetName);

            $materials = [];
            if ($cement !== null && Decimal::of($cement)->isPositive()) {
                $materials[] = [
                    'name' => 'Cement',
                    'consumption_qty' => Decimal::of($cement)->toQuantity(),
                    'consumption_unit' => 'Kg',
                    'rate' => $cementRate !== null ? Decimal::of($cementRate)->toRate() : '0.0000',
                ];
            }
            if ($sand !== null && Decimal::of($sand)->isPositive()) {
                $materials[] = [
                    'name' => 'Sand',
                    'consumption_qty' => Decimal::of($sand)->toQuantity(),
                    'consumption_unit' => 'Cum',
                    'rate' => $sandRate !== null ? Decimal::of($sandRate)->toRate() : '0.0000',
                ];
            }
            if ($agg !== null && Decimal::of($agg)->isPositive()) {
                $materials[] = [
                    'name' => 'Aggregate',
                    'consumption_qty' => Decimal::of($agg)->toQuantity(),
                    'consumption_unit' => 'Cum',
                    'rate' => $aggRate !== null ? Decimal::of($aggRate)->toRate() : '0.0000',
                ];
            }

            $items[] = [
                'source_sheet' => $sheetName,
                'source_row' => $r,
                'section' => $root,
                'subsection' => $currentWorkHead,
                'name' => $b,
                'unit' => $unit,
                'formula_type' => $formulaType->value,
                'nos' => $nos,
                'length' => $length,
                'width' => $width,
                'height_depth' => $height,
                'excel_qty' => $excelQty,
                'erp_qty' => $erpQty,
                'materials' => $materials,
                'labour_rate' => $labourRate !== null ? Decimal::of($labourRate)->toRate() : null,
                'warning' => $qtyWarning,
            ];
        }

        return [
            'sheet' => $sheetName,
            'root_section' => $root,
            'items' => $items,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseRateAnalysis(Worksheet $sheet, string $sheetName): array
    {
        $profiles = [];
        $current = null;
        $highest = (int) $sheet->getHighestDataRow();

        for ($r = 1; $r <= $highest; $r++) {
            $a = $this->cellString($sheet, 'A', $r);
            $b = $this->cellString($sheet, 'B', $r);
            if (str_starts_with(mb_strtolower($a), 'rate for')) {
                if ($current !== null) {
                    $profiles[] = $current;
                }
                $current = [
                    'source_sheet' => $sheetName,
                    'name' => trim(str_ireplace('Rate for', '', $a)),
                    'items' => [],
                    'water_percent' => '0',
                    'excel_total' => null,
                ];

                continue;
            }
            if ($current === null) {
                continue;
            }

            $bLower = mb_strtolower($b);
            if (str_contains($bLower, 'add water')) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $b, $m)) {
                    $current['water_percent'] = $m[1];
                }

                continue;
            }
            if ($bLower === 'total amount' || str_starts_with($bLower, 'total amount')) {
                $discard = [];
                $current['excel_total'] = $this->cellNumeric($sheet, 'F', $r, $discard, $sheetName);

                continue;
            }
            if ($bLower === 'total' || $bLower === 'labour charges' || $b === '') {
                continue;
            }

            $discard = [];
            $qty = $this->cellNumeric($sheet, 'D', $r, $discard, $sheetName);
            $rate = $this->cellNumeric($sheet, 'E', $r, $discard, $sheetName);
            $unit = $this->normalizeUnit($this->cellString($sheet, 'C', $r)) ?? 'Nos';
            if ($qty === null || $rate === null) {
                continue;
            }

            $type = str_contains($bLower, 'labour') || str_contains($bLower, 'mixing')
                ? 'labour'
                : 'material';

            $current['items'][] = [
                'description' => $b,
                'resource_type' => $type,
                'unit' => $unit,
                'quantity' => Decimal::of($qty)->toQuantity(),
                'rate' => Decimal::of($rate)->toRate(),
                'source_row' => $r,
            ];
        }

        if ($current !== null) {
            $profiles[] = $current;
        }

        return $profiles;
    }

    /**
     * @return array{sheet: string, lines: list<array<string, mixed>>, wastage_percent: string, warnings: list<string>}
     */
    public function parseBbs(Worksheet $sheet, string $sheetName): array
    {
        $lines = [];
        $warnings = [];
        $highest = (int) $sheet->getHighestDataRow();

        for ($r = 5; $r <= $highest; $r++) {
            $desc = $this->cellString($sheet, 'B', $r);
            $dia = $this->cellNumeric($sheet, 'C', $r, $warnings, $sheetName);
            if ($desc === '' || $dia === null || ! Decimal::of($dia)->isPositive()) {
                continue;
            }

            $members = $this->cellNumeric($sheet, 'D', $r, $warnings, $sheetName) ?? '1';
            $bars = $this->cellNumeric($sheet, 'E', $r, $warnings, $sheetName) ?? '1';
            $a = $this->cellNumeric($sheet, 'G', $r, $warnings, $sheetName) ?? '0';
            $b = $this->cellNumeric($sheet, 'H', $r, $warnings, $sheetName) ?? '0';
            $c = $this->cellNumeric($sheet, 'I', $r, $warnings, $sheetName) ?? '0';
            $d = $this->cellNumeric($sheet, 'J', $r, $warnings, $sheetName) ?? '0';
            $hook = $this->cellNumeric($sheet, 'K', $r, $warnings, $sheetName) ?? '0';
            $deduction = $this->cellNumeric($sheet, 'L', $r, $warnings, $sheetName) ?? '0';

            $membersInt = max(1, (int) round((float) $members));
            $barsInt = max(1, (int) round((float) $bars));

            $aQty = Decimal::of($a);
            $bQty = Decimal::of($b);
            $cQty = Decimal::of($c);
            $dQty = Decimal::of($d);
            if (! $aQty->isPositive() && ($bQty->isPositive() || $cQty->isPositive() || $dQty->isPositive())) {
                // Workbook often stores primary length in column H when G is empty.
                $aQty = $bQty->plus($cQty)->plus($dQty);
                $bQty = Decimal::zero();
                $cQty = Decimal::zero();
                $dQty = Decimal::zero();
            }
            if (! $aQty->isPositive()) {
                continue;
            }

            try {
                $calc = BbsCalculator::line([
                    'shape_code' => 'custom_whitelisted',
                    'diameter_mm' => (int) round((float) $dia),
                    'number_of_members' => $membersInt,
                    'bars_per_member' => $barsInt,
                    'a_length' => $aQty->toQuantity(),
                    'b_length' => $bQty->toQuantity(),
                    'c_length' => $cQty->toQuantity(),
                    'd_length' => $dQty->toQuantity(),
                    'hook_length' => Decimal::of($hook)->toQuantity(),
                    'bend_deduction' => Decimal::of($deduction)->toQuantity(),
                ]);
            } catch (\Throwable $e) {
                $warnings[] = "BBS row {$r} on [{$sheetName}] skipped: ".$e->getMessage();

                continue;
            }

            $lines[] = [
                'source_row' => $r,
                'description' => $desc,
                'diameter_mm' => (int) round((float) $dia),
                'number_of_members' => $membersInt,
                'bars_per_member' => $barsInt,
                'a_length' => $aQty->toQuantity(),
                'b_length' => $bQty->toQuantity(),
                'c_length' => $cQty->toQuantity(),
                'd_length' => $dQty->toQuantity(),
                'hook_length' => Decimal::of($hook)->toQuantity(),
                'bend_deduction' => Decimal::of($deduction)->toQuantity(),
                'erp_total_length' => $calc['total_length'],
                'erp_theoretical_weight' => $calc['theoretical_weight'],
                'structure_location' => $desc,
                'bar_mark' => 'R'.$r,
            ];
        }

        return [
            'sheet' => $sheetName,
            'lines' => $lines,
            'wastage_percent' => '5', // workbook summary uses 5%; stored on BBS header snapshot, not global default
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseRateComparison(Worksheet $sheet, string $sheetName): array
    {
        $rows = [];
        $highest = (int) $sheet->getHighestDataRow();
        $headers = [
            'D' => $this->cellString($sheet, 'D', 3) ?: 'Manual / AJAX',
            'E' => $this->cellString($sheet, 'E', 3) ?: 'Krishna',
            'F' => $this->cellString($sheet, 'F', 3) ?: 'Gryroot',
        ];

        for ($r = 4; $r <= $highest; $r++) {
            $desc = $this->cellString($sheet, 'B', $r);
            $unit = $this->normalizeUnit($this->cellString($sheet, 'C', $r));
            if ($desc === '' || $unit === null) {
                continue;
            }
            foreach ($headers as $col => $vendor) {
                $discard = [];
                $rate = $this->cellNumeric($sheet, $col, $r, $discard, $sheetName);
                if ($rate === null) {
                    continue;
                }
                $rows[] = [
                    'source_sheet' => $sheetName,
                    'source_row' => $r,
                    'description' => $desc,
                    'unit' => $unit,
                    'vendor_name' => preg_replace('/\s*\(.*$/', '', $vendor) ?: $vendor,
                    'quoted_rate' => Decimal::of($rate)->toRate(),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function parseSteelSummary(Worksheet $sheet, string $sheetName, array &$warnings): array
    {
        $wastage = '5';
        for ($r = 1; $r <= (int) $sheet->getHighestDataRow(); $r++) {
            $b = mb_strtolower($this->cellString($sheet, 'B', $r));
            if (str_contains($b, 'wastage') && preg_match('/(\d+(?:\.\d+)?)\s*%/', $this->cellString($sheet, 'B', $r), $m)) {
                $wastage = $m[1];
            }
            $this->scanBrokenRefs($sheet, $r, $sheetName, $warnings);
        }

        return [
            'sheet' => $sheetName,
            'wastage_percent' => $wastage,
            'role' => 'reconcile_only',
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function parseMaterialConsumption(Worksheet $sheet, string $sheetName, array &$warnings): array
    {
        $rows = [];
        for ($r = 1; $r <= (int) $sheet->getHighestDataRow(); $r++) {
            $this->scanBrokenRefs($sheet, $r, $sheetName, $warnings);
            $name = $this->cellString($sheet, 'B', $r);
            $unit = $this->normalizeUnit($this->cellString($sheet, 'C', $r));
            $qty = $this->cellNumeric($sheet, 'D', $r, $warnings, $sheetName);
            if ($name === '' || $unit === null || $qty === null) {
                continue;
            }
            if (str_starts_with(mb_strtolower($name), 'note')) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'unit' => $unit,
                'excel_qty' => Decimal::of($qty)->toQuantity(),
                'source_row' => $r,
            ];
        }

        return ['sheet' => $sheetName, 'rows' => $rows, 'role' => 'reconcile_only'];
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function parseLabourSummary(Worksheet $sheet, string $sheetName, array &$warnings): array
    {
        $rows = [];
        for ($r = 1; $r <= (int) $sheet->getHighestDataRow(); $r++) {
            $this->scanBrokenRefs($sheet, $r, $sheetName, $warnings);
            $name = $this->cellString($sheet, 'B', $r);
            $unit = $this->normalizeUnit($this->cellString($sheet, 'C', $r));
            $rate = $this->cellNumeric($sheet, 'D', $r, $warnings, $sheetName);
            $qty = $this->cellNumeric($sheet, 'E', $r, $warnings, $sheetName);
            if ($name === '' || $unit === null || $rate === null) {
                continue;
            }
            if (str_contains(mb_strtolower($name), 'total labour')) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'unit' => $unit,
                'rate' => Decimal::of($rate)->toRate(),
                'excel_qty' => $qty !== null ? Decimal::of($qty)->toQuantity() : null,
                'source_row' => $r,
            ];
        }

        return ['sheet' => $sheetName, 'rows' => $rows, 'role' => 'reconcile_only'];
    }

    /**
     * @return array{sheet: string, activities: list<array<string, mixed>>}
     */
    public function parseSchedule(Worksheet $sheet, string $sheetName): array
    {
        $activities = [];
        $highest = (int) $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highest; $r++) {
            $name = $this->cellString($sheet, 'B', $r) ?: $this->cellString($sheet, 'A', $r);
            if ($name === '' || str_contains(mb_strtolower($name), 'schedule')) {
                continue;
            }
            $activities[] = [
                'source_row' => $r,
                'name' => $name,
                'unit' => $this->normalizeUnit($this->cellString($sheet, 'C', $r)),
            ];
        }

        return ['sheet' => $sheetName, 'activities' => $activities];
    }

    private function inferFormulaType(?string $nos, ?string $length, ?string $width, ?string $height, string $unit): ?MeasurementFormulaType
    {
        $hasL = $length !== null && Decimal::of($length)->isPositive();
        $hasW = $width !== null && Decimal::of($width)->isPositive();
        $hasH = $height !== null && Decimal::of($height)->isPositive();

        if ($hasL && $hasW && $hasH) {
            return MeasurementFormulaType::Volume;
        }
        if ($hasL && $hasW) {
            return MeasurementFormulaType::Area;
        }
        if ($hasL && $hasH && ! $hasW) {
            // Shuttering-style Nos × L × H
            return MeasurementFormulaType::Area;
        }
        if ($hasL) {
            return MeasurementFormulaType::Length;
        }
        if ($nos !== null) {
            return MeasurementFormulaType::Number;
        }

        return null;
    }

    private function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }
        $u = mb_strtolower(trim($unit));
        $u = str_replace(["'", '’'], '', $u);

        return match ($u) {
            'cum', 'cu.m', 'cu m', 'm3', 'm³' => 'Cum',
            'sqm', 'sq.m', 'sq m', 'm2', 'm²', 'sqmt' => 'Sqm',
            'sft', 'sqft', 'sq.ft' => 'Sft',
            'rmt', 'r.mt', 'rm', 'mtr', 'm', 'meter', 'metre' => 'Rmt',
            'kg', 'kgs' => 'Kg',
            'mt', 'ton', 'tonne', 't' => 'MT',
            'nos', 'no', 'no.', 'nos.', 'no\'s', 'nos' => 'Nos',
            'bags', 'bag' => 'Bag',
            'cft', 'cu.ft' => 'Cft',
            default => ucfirst($unit),
        };
    }

    /**
     * @param  list<string>  $warnings
     */
    private function cellNumeric(Worksheet $sheet, string $col, int $row, array &$warnings, string $sheetName): ?string
    {
        $cell = $sheet->getCell($col.$row);
        $raw = $cell->getValue();
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $trim = trim($raw);
            foreach (self::EXCEL_ERRORS as $err) {
                if (str_contains($trim, $err)) {
                    $warnings[] = "BROKEN_EXCEL_REFERENCE on {$sheetName}!{$col}{$row}";

                    return null;
                }
            }
        }

        try {
            $value = $cell->getCalculatedValue();
        } catch (\Throwable) {
            $warnings[] = "BROKEN_EXCEL_REFERENCE on {$sheetName}!{$col}{$row}";

            return null;
        }

        if ($value === null || $value === '' || ! is_numeric($value)) {
            if (is_string($value)) {
                foreach (self::EXCEL_ERRORS as $err) {
                    if (str_contains($value, $err)) {
                        $warnings[] = "BROKEN_EXCEL_REFERENCE on {$sheetName}!{$col}{$row}";

                        return null;
                    }
                }
            }

            return null;
        }

        return Decimal::of((string) $value)->toQuantity();
    }

    private function cellString(Worksheet $sheet, string $col, int $row): string
    {
        $value = $sheet->getCell($col.$row)->getValue();
        if ($value === null) {
            return '';
        }

        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * @param  list<string>  $warnings
     */
    private function scanBrokenRefs(Worksheet $sheet, int $row, string $sheetName, array &$warnings): void
    {
        foreach (range('A', 'U') as $col) {
            $raw = $sheet->getCell($col.$row)->getValue();
            if (is_string($raw)) {
                foreach (self::EXCEL_ERRORS as $err) {
                    if (str_contains($raw, $err)) {
                        $warnings[] = "BROKEN_EXCEL_REFERENCE on {$sheetName}!{$col}{$row}";
                        break;
                    }
                }
            }
        }
    }
}
