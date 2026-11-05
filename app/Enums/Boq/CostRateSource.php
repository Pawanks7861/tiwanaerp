<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum CostRateSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case MaterialMaster = 'material_master';
    case LatestPo = 'latest_po';
    case LabourMaster = 'labour_master';
    case EquipmentMaster = 'equipment_master';
    case RateLibrary = 'rate_library';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual estimate',
            self::MaterialMaster => 'Material master',
            self::LatestPo => 'Latest approved PO',
            self::LabourMaster => 'Labour master',
            self::EquipmentMaster => 'Equipment master',
            self::RateLibrary => 'Rate library',
        };
    }
}
