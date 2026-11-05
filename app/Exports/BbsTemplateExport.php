<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * BBS import template. Derived totals (bars, cutting length, weights) are ignored on import.
 */
class BbsTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'BBS Import';
    }

    public function headings(): array
    {
        return [
            'Location', 'Bar Mark', 'Shape', 'Diameter mm', 'Members', 'Bars Per Member',
            'A m', 'B m', 'C m', 'D m', 'Hook Length m', 'Bend Deduction m', 'Remarks',
        ];
    }

    public function array(): array
    {
        return [
            ['Beam B1', 'B1-M1', 'straight', 16, 10, 4, 6.000, '', '', '', 0, 0, 'Example straight'],
            ['Col C1', 'C1-L1', 'l_bar', 12, 8, 4, 3.000, 1.200, '', '', 0.200, 0.050, 'Example L'],
            ['Slab', 'S-ST8', 'stirrup', 8, 20, 1, 0.250, 0.400, '', '', 0.100, 0.040, 'Example stirrup'],
        ];
    }
}
