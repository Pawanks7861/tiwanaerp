<?php

namespace App\Exports;

use App\Models\Projects\Project;
use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * @implements WithMapping<array<string, mixed>>
 */
class SteelReconciliationExport implements FromArray, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly Project $project,
        private readonly array $rows,
        private readonly array $filters = [],
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Steel Reconciliation';
    }

    public function headings(): array
    {
        return [
            'Project',
            'Generated At',
            'Filters',
            'Diameter mm',
            'Material Code',
            'Material',
            'Unit',
            'Theoretical Weight',
            'Wastage Weight',
            'Required Weight',
            'Required MT',
            'MR Qty',
            'PO Qty',
            'GRN Qty',
            'Issued Qty',
            'Returned Qty',
            'Net Issued',
            'Central Stock Available',
            'Issue Variance',
            'Variance %',
            'Status',
            'Source',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $filterText = collect($this->filters)
            ->filter(fn ($v, $k) => $v !== null && $v !== '' && ! str_starts_with((string) $k, '_'))
            ->map(fn ($v, $k) => "{$k}={$v}")
            ->implode('; ');

        return [
            SpreadsheetText::cell($this->project->code.' — '.$this->project->name),
            SpreadsheetText::cell(now()->toDateTimeString()),
            SpreadsheetText::cell($filterText !== '' ? $filterText : 'none'),
            SpreadsheetText::cell((string) $row['diameter_mm']),
            SpreadsheetText::cell((string) $row['material_code']),
            SpreadsheetText::cell((string) $row['material_name']),
            SpreadsheetText::cell((string) $row['unit']),
            SpreadsheetText::cell((string) $row['theoretical_weight']),
            SpreadsheetText::cell((string) $row['wastage_weight']),
            SpreadsheetText::cell((string) $row['required_weight']),
            SpreadsheetText::cell((string) $row['required_weight_mt']),
            SpreadsheetText::cell((string) $row['mr_qty']),
            SpreadsheetText::cell((string) $row['po_qty']),
            SpreadsheetText::cell((string) $row['grn_qty']),
            SpreadsheetText::cell((string) $row['issued_qty']),
            SpreadsheetText::cell((string) $row['returned_qty']),
            SpreadsheetText::cell((string) $row['net_issued']),
            SpreadsheetText::cell((string) $row['central_stock_available']),
            SpreadsheetText::cell((string) $row['issue_variance']),
            SpreadsheetText::cell($row['variance_percent'] !== null ? (string) $row['variance_percent'] : ''),
            SpreadsheetText::cell((string) $row['status']),
            SpreadsheetText::cell((string) $row['requirement_source']),
        ];
    }
}
