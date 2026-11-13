<?php

namespace App\Enums\Subcontract;

/**
 * Presentation profile for Work Order PDFs / terms templates. Does not change financial workflow.
 */
enum WorkOrderTemplateProfile: string
{
    case CivilWork = 'civil_work';
    case LabourRate = 'labour_rate';
    case EquipmentRental = 'equipment_rental';
    case Fabrication = 'fabrication';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::CivilWork => 'Civil work',
            self::LabourRate => 'Labour / rate contract',
            self::EquipmentRental => 'Equipment rental',
            self::Fabrication => 'Fabrication',
            self::General => 'General',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}
