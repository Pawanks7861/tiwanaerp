<?php

namespace App\Exports\Imports;

use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImportErrorReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(private readonly array $rows) {}

    public function title(): string
    {
        return 'Errors';
    }

    public function headings(): array
    {
        return ['Source Sheet', 'Source Row', 'Item Code', 'Description', 'Field', 'Error'];
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            foreach ($row['errors'] ?? [] as $error) {
                $out[] = [
                    SpreadsheetText::cell($row['sheet'] ?? ''),
                    $row['row'] ?? '',
                    SpreadsheetText::cell($row['preview']['code'] ?? $row['preview']['item_code'] ?? ''),
                    SpreadsheetText::cell($row['preview']['name'] ?? ''),
                    SpreadsheetText::cell($error['field'] ?? ''),
                    SpreadsheetText::cell($error['message'] ?? ''),
                ];
            }
        }

        return $out;
    }
}
