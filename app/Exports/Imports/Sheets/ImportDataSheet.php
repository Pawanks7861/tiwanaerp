<?php

namespace App\Exports\Imports\Sheets;

use App\Support\Exports\SpreadsheetText;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * First sheet of an import template: headers + example rows. Must stay sheet index 0
 * so GenericRowsImport reads the data columns, not Instructions.
 */
class ImportDataSheet implements Export, FromArray, WithEvents, WithTitle
{
    public function __construct(private readonly ImportDefinition $definition) {}

    public function title(): string
    {
        return 'Import Data';
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $fields = $this->definition->fields();
        $keys = array_map(fn (ImportField $f) => $f->key, $fields);
        $rows = [array_map(fn (ImportField $f) => $f->label, $fields)];

        foreach ($this->definition->exampleRows() as $example) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = SpreadsheetText::cell($example[$key] ?? null);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $fields = $this->definition->fields();
                $colCount = count($fields);
                if ($colCount < 1) {
                    return;
                }

                $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
                $headerRange = "A1:{$lastCol}1";

                $sheet->getStyle($headerRange)->getFont()->setBold(true);
                $sheet->freezePane('A2');
                $sheet->setAutoFilter($headerRange);

                foreach ($fields as $index => $field) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->getColumnDimension($col)->setWidth(max(14, min(36, strlen($field->label) + 4)));

                    $fill = $field->required ? 'FDE68A' : 'E2E8F0';
                    $sheet->getStyle("{$col}1")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($fill);

                    $comment = trim(($field->required ? 'Required. ' : 'Optional. ').($field->help ?? ''));
                    if ($field->allowed) {
                        $comment .= ' Allowed: '.implode(', ', array_slice($field->allowed, 0, 20));
                    }
                    if ($comment !== '') {
                        $sheet->getComment("{$col}1")->getText()->createTextRun($comment);
                    }

                    if ($field->type === 'date') {
                        $sheet->getStyle("{$col}2:{$col}500")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                    } elseif (in_array($field->type, ['number', 'money', 'rate', 'qty', 'percent'], true)) {
                        $sheet->getStyle("{$col}2:{$col}500")->getNumberFormat()->setFormatCode('#,##0.####');
                    }

                    if ($field->allowed && count($field->allowed) <= 50) {
                        $this->dropdown($sheet, $col, $field->allowed);
                    }
                }
            },
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function dropdown(Worksheet $sheet, string $col, array $values): void
    {
        $list = '"'.implode(',', array_map(
            fn ($v) => str_replace(['"', ','], ['', ' '], (string) $v),
            $values,
        )).'"';

        if (strlen($list) > 250) {
            return;
        }

        $validation = $sheet->getCell("{$col}2")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setFormula1($list);
        $validation->setSqref("{$col}2:{$col}500");
    }
}
