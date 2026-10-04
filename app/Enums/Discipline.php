<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Engineering discipline of a drawing or a quality checklist.
 */
enum Discipline: string
{
    use HasOptions;

    case Architectural = 'architectural';
    case Structural = 'structural';
    case Civil = 'civil';
    case Electrical = 'electrical';
    case Plumbing = 'plumbing';
    case Hvac = 'hvac';
    case FireFighting = 'fire_fighting';
    case Interior = 'interior';
    case Landscape = 'landscape';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Hvac => 'HVAC',
            self::FireFighting => 'Fire fighting',
            default => ucfirst($this->value),
        };
    }
}
