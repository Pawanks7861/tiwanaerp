<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

/**
 * Resource lines of a rate analysis. Each type may reference its own master.
 */
enum ResourceType: string
{
    use HasOptions;

    case Material = 'material';
    case Labour = 'labour';
    case Equipment = 'equipment';
    case Subcontract = 'subcontract';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Column holding the master reference for this type, if any. */
    public function masterColumn(): ?string
    {
        return match ($this) {
            self::Material => 'material_id',
            self::Labour => 'labour_trade_id',
            self::Equipment => 'equipment_type_id',
            self::Subcontract, self::Other => null,
        };
    }
}
