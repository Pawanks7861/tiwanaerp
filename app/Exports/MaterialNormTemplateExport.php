<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable Material Norm import template (Stage 9). Derived totals are calculated server-side.
 */
class MaterialNormTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        return [
            'material_code',
            'consumption_qty',
            'consumption_unit',
            'wastage_percent',
            'notes',
        ];
    }

    public function array(): array
    {
        return [
            ['CEM-OPC53', '365', 'Kg', '0', 'Example cement norm per BOQ unit'],
            ['SAND-M', '0.45', 'Cum', '2.5', ''],
        ];
    }
}
