<?php

namespace App\Exports;

use App\Models\Boq\BoqBbs;
use App\Services\Boq\BbsService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class BbsExport implements WithMultipleSheets
{
    public function __construct(private readonly BoqBbs $bbs) {}

    public function sheets(): array
    {
        $payload = app(BbsService::class)->bbsPayload($this->bbs->loadMissing(['lines', 'steelMaps.material', 'drawingRevision.drawing']));

        return [
            new class($payload) implements FromArray, ShouldAutoSize, WithTitle
            {
                public function __construct(private array $payload) {}

                public function title(): string
                {
                    return 'BBS Lines';
                }

                public function array(): array
                {
                    $rows = [[
                        'Location', 'Bar Mark', 'Shape', 'Dia mm', 'Members', 'Bars/Member', 'Total Bars',
                        'A m', 'B m', 'C m', 'D m', 'E m', 'Hook m', 'Bend Deduction m',
                        'Cutting Length m', 'Total Length m', 'Unit Weight kg/m', 'Weight kg', 'Remarks',
                    ]];
                    foreach ($this->payload['lines'] as $l) {
                        $rows[] = [
                            $l['structure_location'], $l['bar_mark'], $l['shape_code'], $l['diameter_mm'],
                            $l['number_of_members'], $l['bars_per_member'], $l['total_bars'],
                            $l['a_length'], $l['b_length'], $l['c_length'], $l['d_length'], $l['e_length'],
                            $l['hook_length'], $l['bend_deduction'], $l['cutting_length'], $l['total_length'],
                            $l['unit_weight'], $l['theoretical_weight'], $l['remarks'],
                        ];
                    }

                    return $rows;
                }
            },
            new class($payload) implements FromArray, ShouldAutoSize, WithTitle
            {
                public function __construct(private array $payload) {}

                public function title(): string
                {
                    return 'Summary';
                }

                public function array(): array
                {
                    $rows = [
                        ['Title', $this->payload['title']],
                        ['Status', $this->payload['status_label']],
                        ['Total Bars', $this->payload['total_bars']],
                        ['Total Length m', $this->payload['total_length']],
                        ['Theoretical Weight kg', $this->payload['total_theoretical_weight']],
                        ['Theoretical Weight MT', $this->payload['total_theoretical_weight_mt']],
                        ['Wastage %', $this->payload['wastage_percent']],
                        ['Wastage Weight kg', $this->payload['wastage_weight']],
                        ['Required Steel kg', $this->payload['required_weight']],
                        ['Required Steel MT', $this->payload['required_weight_mt']],
                        [],
                        ['Diameter mm', 'Total Length m', 'Theoretical kg', 'Wastage kg', 'Required kg', 'Material'],
                    ];
                    foreach ($this->payload['diameter_summary'] as $d) {
                        $rows[] = [
                            $d['diameter_mm'], $d['total_length'], $d['theoretical_weight'],
                            $d['wastage_weight'], $d['required_weight'], $d['material'] ?? $d['mapping_status'],
                        ];
                    }

                    return $rows;
                }
            },
        ];
    }
}
