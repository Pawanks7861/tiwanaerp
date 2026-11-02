<?php

namespace App\Exports\Imports\Sheets;

use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ImportReferenceSheet implements Export, FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    /**
     * @param  array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}  $sheet
     */
    public function __construct(
        private readonly array $sheet,
        private readonly int $index = 0,
    ) {}

    public function title(): string
    {
        $base = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->sheet['title']) ?: 'Reference';

        return mb_substr('Ref '.($this->index + 1).' '.$base, 0, 31);
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [];
        if (! empty($this->sheet['note'])) {
            $rows[] = [SpreadsheetText::cell($this->sheet['note'])];
            $rows[] = [];
        }
        $rows[] = array_map(fn ($h) => SpreadsheetText::cell($h), $this->sheet['headings']);
        foreach ($this->sheet['rows'] as $row) {
            $rows[] = array_map(fn ($cell) => SpreadsheetText::cell($cell), $row);
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $headerRow = empty($this->sheet['note']) ? 1 : 3;
                $colCount = max(1, count($this->sheet['headings']));
                $lastCol = Coordinate::stringFromColumnIndex($colCount);
                $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E2E8F0');
                $sheet->freezePane('A'.($headerRow + 1));
            },
        ];
    }
}
