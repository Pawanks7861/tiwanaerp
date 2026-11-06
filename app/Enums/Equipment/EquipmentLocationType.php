<?php

namespace App\Enums\Equipment;

use App\Enums\Concerns\HasOptions;

enum EquipmentLocationType: string
{
    use HasOptions;

    case CentralStore = 'central_store';
    case ProjectSite = 'project_site';
    case Workshop = 'workshop';
    case Vendor = 'vendor';
    case InTransit = 'in_transit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CentralStore => 'Central Store',
            self::ProjectSite => 'Project Site',
            self::Workshop => 'Workshop',
            self::Vendor => 'Vendor',
            self::InTransit => 'In Transit',
            self::Other => 'Other',
        };
    }
}
