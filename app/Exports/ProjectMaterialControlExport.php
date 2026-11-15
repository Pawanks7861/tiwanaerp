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
class ProjectMaterialControlExport implements FromArray, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly Project $project,
        private readonly array $rows,
        private readonly array $filters = [],
        private readonly bool $withCosts = false,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'Material Control';
    }

    public function headings(): array
    {
        $heads = [
            'Project',
            'Generated At',
            'Material Code',
            'Material',
            'Unit',
            'Estimated Qty',
            'Budget Qty',
            'MR Qty',
            'PO Qty',
            'GRN Qty',
            'Issued Qty',
            'Returned Qty',
            'Net Consumed',
            'Remaining Estimate',
            'Variance Qty',
            'Variance %',
            'Status',
        ];

        if ($this->withCosts) {
            $heads = array_merge($heads, [
                'Estimated Rate',
                'Estimated Cost',
                'Actual Avg Rate',
                'Actual Cost',
                'Cost Variance',
            ]);
        }

        return $heads;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $base = [
            SpreadsheetText::of($this->project->code),
            now()->toDateTimeString(),
            SpreadsheetText::of($row['material_code'] ?? ''),
            SpreadsheetText::of($row['material_name'] ?? ''),
            SpreadsheetText::of($row['unit'] ?? ''),
            SpreadsheetText::of($row['estimated_qty'] ?? ''),
            SpreadsheetText::of($row['budget_qty'] ?? ''),
            SpreadsheetText::of($row['mr_qty'] ?? ''),
            SpreadsheetText::of($row['po_qty'] ?? ''),
            SpreadsheetText::of($row['grn_qty'] ?? ''),
            SpreadsheetText::of($row['issued_qty'] ?? ''),
            SpreadsheetText::of($row['returned_qty'] ?? ''),
            SpreadsheetText::of($row['net_consumed_qty'] ?? ''),
            SpreadsheetText::of($row['remaining_estimate'] ?? ''),
            SpreadsheetText::of($row['variance_qty'] ?? ''),
            SpreadsheetText::of($row['variance_percent_label'] ?? $row['variance_percent'] ?? ''),
            SpreadsheetText::of($row['status'] ?? ''),
        ];

        if ($this->withCosts) {
            $base = array_merge($base, [
                SpreadsheetText::of($row['estimated_rate'] ?? ''),
                SpreadsheetText::of($row['estimated_cost'] ?? ''),
                SpreadsheetText::of($row['actual_avg_rate'] ?? ''),
                SpreadsheetText::of($row['actual_consumption_cost'] ?? ''),
                SpreadsheetText::of($row['cost_variance'] ?? ''),
            ]);
        }

        return $base;
    }
}
