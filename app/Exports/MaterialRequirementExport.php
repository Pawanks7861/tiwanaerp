<?php

namespace App\Exports;

use App\Models\Projects\Project;
use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * @implements WithMapping<array<string, mixed>>
 */
class MaterialRequirementExport implements FromArray, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private readonly Project $project, private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Material Code', 'Material', 'Unit', 'Base Requirement', 'Wastage %', 'Wastage Qty',
            'Required Qty', 'MR Qty', 'PO Qty', 'Received Qty', 'Issued Qty', 'Returned Qty',
            'Available Stock', 'Remaining Requirement', 'Over-procured Qty',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return [
            SpreadsheetText::cell((string) $row['material_code']),
            SpreadsheetText::cell((string) $row['material_name']),
            SpreadsheetText::cell((string) $row['unit']),
            SpreadsheetText::cell((string) $row['base_requirement']),
            SpreadsheetText::cell((string) $row['wastage_percent']),
            SpreadsheetText::cell((string) $row['wastage_qty']),
            SpreadsheetText::cell((string) $row['required_qty']),
            SpreadsheetText::cell((string) $row['mr_qty']),
            SpreadsheetText::cell((string) $row['po_qty']),
            SpreadsheetText::cell((string) $row['received_qty']),
            SpreadsheetText::cell((string) $row['issued_qty']),
            SpreadsheetText::cell((string) $row['returned_qty']),
            SpreadsheetText::cell((string) $row['available_stock']),
            SpreadsheetText::cell((string) $row['remaining_requirement']),
            SpreadsheetText::cell((string) $row['over_procured_qty']),
        ];
    }
}
