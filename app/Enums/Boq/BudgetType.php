<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

/**
 * Separates management Main Project Budget from BOQ-derived technical estimate.
 * Same table, independent version streams — never mixed in Cost Control totals.
 */
enum BudgetType: string
{
    use HasOptions;

    case MainProject = 'main_project';
    case BoqEstimate = 'boq_estimate';

    public function label(): string
    {
        return match ($this) {
            self::MainProject => 'Main Project Budget',
            self::BoqEstimate => 'BOQ Estimate',
        };
    }
}
