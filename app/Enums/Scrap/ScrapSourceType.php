<?php

namespace App\Enums\Scrap;

use App\Enums\Concerns\HasOptions;

enum ScrapSourceType: string
{
    use HasOptions;

    case SiteWork = 'site_work';
    case MaterialWaste = 'material_waste';
    case Dismantling = 'dismantling';
    case EquipmentMaintenance = 'equipment_maintenance';
    case ElectricalMaintenance = 'electrical_maintenance';
    case Packaging = 'packaging';
    case RejectedMaterial = 'rejected_material';
    case StoreCleaning = 'store_cleaning';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SiteWork => 'Site work',
            self::MaterialWaste => 'Material waste',
            self::Dismantling => 'Dismantling',
            self::EquipmentMaintenance => 'Equipment maintenance',
            self::ElectricalMaintenance => 'Electrical maintenance',
            self::Packaging => 'Packaging',
            self::RejectedMaterial => 'Rejected material',
            self::StoreCleaning => 'Store cleaning',
            self::Other => 'Other',
        };
    }
}
