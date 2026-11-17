<?php

namespace App\Exports;

use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProjectCostControlExport implements FromArray, ShouldAutoSize, WithStrictNullComparison, WithTitle
{
    /**
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $sheetTitle = 'Cost Control',
    ) {}

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->sheetTitle) ?? 'Cost Control', 0, 31);
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return array_map(
            fn (array $row) => array_map(fn (mixed $cell) => SpreadsheetText::cell($cell), $row),
            $this->rows,
        );
    }
}
