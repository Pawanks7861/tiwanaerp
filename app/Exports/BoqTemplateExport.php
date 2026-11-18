<?php

namespace App\Exports;

use App\Services\Boq\BoqImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Sample BOQ import file with the expected headings and two example lines.
 */
class BoqTemplateExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'BOQ';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return BoqImportService::HEADINGS;
    }

    /**
     * @return list<list<string|null>>
     */
    public function array(): array
    {
        // Section Code, Section Name, Subsection Code, Subsection Name, Item Code, Name, Description,
        // HSN SAC, Unit, Measurement Nos, Length, Width, Height, Measurement Mode, Quantity,
        // Material/Labour/Equipment/Subcontract/Margin, Client Rate, Amount, Remarks
        // (GST removed from BOQ — Amount = Qty × Rate)
        return [
            ['A', 'Civil Works', 'A.1', 'Excavation', 'C-001', 'Earthwork excavation in ordinary soil', 'Including disposal within 50 m', '995419', 'Cum', '1', '18.03', '44.92', '3.5', 'auto', '2834.6766', '0', '185.5', '60', '0', '15', '', '', ''],
            ['A', 'Civil Works', 'A.2', 'Concrete', 'C-002', 'PCC M15 (1:2:4)', 'Including formwork', '995419', 'Cum', '', '', '', '', 'manual', '35', '4200', '850', '250', '0', '12.5', '', '', ''],
        ];
    }
}
