<?php

namespace App\Exports;

use App\Reports\ReportResult;
use App\Support\Exports\SpreadsheetText;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One report as a worksheet: header block (company, report, project, period, generated at / by,
 * filters), then the table with a bold frozen heading row, auto filter, numeric cells with
 * number formats, a totals row and any extra sections below.
 */
class ReportSheetExport implements FromArray, WithEvents, WithTitle
{
    private const NUMERIC = ['money', 'rate', 'qty', 'number', 'percent'];

    private const FORMATS = [
        'money' => '#,##0.00', 'rate' => '#,##0.00##', 'qty' => '#,##0.####', 'number' => '#,##0.##', 'percent' => '0.00"%"',
    ];

    private int $headingRow = 0;

    /** @var list<array{row: int, columns: list<array<string, mixed>>, first: int, last: int}> */
    private array $tables = [];

    /**
     * @param  array{title: string, company: string, project: string, period: string, generated_at: string, generated_by: string, filters: list<string>, result: ReportResult}  $document
     */
    public function __construct(private readonly array $document) {}

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->document['title']), 0, 31);
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $d = $this->document;
        $rows = [
            [$d['company']],
            [$d['title']],
            ['Project: '.$d['project']],
            ['Period: '.$d['period']],
            ['Generated: '.$d['generated_at'].' by '.$d['generated_by']],
            ['Filters: '.($d['filters'] === [] ? 'None' : implode('; ', $d['filters']))],
            [],
        ];

        foreach ($d['result']->cards as $card) {
            $rows[] = [$card['label'], is_scalar($card['value']) ? (string) $card['value'] : null];
        }
        if ($d['result']->cards !== []) {
            $rows[] = [];
        }

        $this->appendTable($rows, $d['result']->columns, $d['result']->rows, $d['result']->totals, true);
        foreach ($d['result']->sections as $section) {
            $rows[] = [];
            $rows[] = [$section['title']];
            $this->appendTable($rows, $section['columns'], $section['rows'], $section['totals'] ?? null, false);
        }
        foreach ($d['result']->notes as $note) {
            $rows[] = [];
            $rows[] = [$note];
        }

        return array_map(
            fn (array $row) => array_map(fn (mixed $cell) => SpreadsheetText::cell($cell), $row),
            $rows,
        );
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                /** @var Worksheet $sheet */
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

                foreach ($this->tables as $i => $table) {
                    $count = count($table['columns']);
                    $lastCol = Coordinate::stringFromColumnIndex(max(1, $count));
                    $sheet->getStyle("A{$table['row']}:{$lastCol}{$table['row']}")->getFont()->setBold(true);
                    $sheet->getStyle("A{$table['row']}:{$lastCol}{$table['row']}")->getFill()->setFillType('solid')->getStartColor()->setRGB('E2E8F0');

                    foreach ($table['columns'] as $index => $column) {
                        $letter = Coordinate::stringFromColumnIndex($index + 1);
                        $type = $column['type'] ?? 'text';
                        if (! in_array($type, self::NUMERIC, true)) {
                            continue;
                        }
                        for ($r = $table['first']; $r <= $table['last']; $r++) {
                            $cell = $sheet->getCell("{$letter}{$r}");
                            $value = $cell->getValue();
                            if ($value !== null && $value !== '' && is_numeric($value)) {
                                $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
                            }
                        }
                        $sheet->getStyle("{$letter}{$table['first']}:{$letter}{$table['last']}")->getNumberFormat()->setFormatCode(self::FORMATS[$type]);
                    }

                    if ($i === 0) {
                        $sheet->freezePane('A'.($table['row'] + 1));
                        if ($table['last'] >= $table['first']) {
                            $sheet->setAutoFilter("A{$table['row']}:{$lastCol}".max($table['row'], $table['last'] - ($table['totals'] ? 1 : 0)));
                        }
                    }
                    if ($table['totals']) {
                        $sheet->getStyle("A{$table['last']}:{$lastCol}{$table['last']}")->getFont()->setBold(true);
                    }
                }

                $widest = max(array_map(fn ($t) => count($t['columns']), $this->tables ?: [['columns' => [1]]]));
                for ($c = 1; $c <= $widest; $c++) {
                    $letter = Coordinate::stringFromColumnIndex($c);
                    $sheet->getColumnDimension($letter)->setWidth($c === 1 ? 34 : 16);
                }
            },
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function appendTable(array &$rows, array $columns, array $data, ?array $totals, bool $main): void
    {
        $rows[] = array_map(fn ($c) => $c['label'], $columns);
        $headingRow = count($rows);
        foreach ($data as $row) {
            $rows[] = array_map(function ($column) use ($row) {
                $raw = $row[$column['key']] ?? null;
                $type = $column['type'] ?? 'text';
                if (in_array($type, self::NUMERIC, true) && is_numeric($raw)) {
                    return $raw;
                }

                return SpreadsheetText::cell($this->value($raw));
            }, $columns);
        }
        if ($totals) {
            $rows[] = array_map(fn ($c) => $this->value($totals[$c['key']] ?? null), $columns);
        }
        $this->tables[] = ['row' => $headingRow, 'columns' => $columns, 'first' => $headingRow + 1, 'last' => count($rows), 'totals' => (bool) $totals];
        if ($main) {
            $this->headingRow = $headingRow;
        }
    }

    private function value(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
    }
}
