<?php

namespace App\Exports\Imports\Sheets;

use App\Support\Exports\SpreadsheetText;
use App\Support\Imports\ImportDefinition;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ImportInstructionsSheet implements Export, FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(private readonly ImportDefinition $definition) {}

    public function title(): string
    {
        return 'Instructions';
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [
            ['Tiwana ERP — Import template'],
            ['Module', $this->definition->label()],
            ['Key', $this->definition->key()],
            [],
            ['Instructions'],
        ];

        foreach ($this->definition->instructionLines() as $line) {
            $rows[] = [SpreadsheetText::cell($line)];
        }

        $rows[] = [];
        $rows[] = ['Field legend on Import Data sheet'];
        $rows[] = ['Yellow header', 'Required field'];
        $rows[] = ['Grey header', 'Optional field'];
        $rows[] = ['EXAMPLE rows', 'Ignored by the importer — delete or leave as-is'];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A5')->getFont()->setBold(true);
                $sheet->getColumnDimension('A')->setWidth(28);
                $sheet->getColumnDimension('B')->setWidth(60);
                $sheet->getStyle('A1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('DBEAFE');
            },
        ];
    }
}
