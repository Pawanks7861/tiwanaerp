<?php

namespace App\Enums\Quality;

use App\Enums\Concerns\HasOptions;

enum InspectionResult: string
{
    use HasOptions;

    case Passed = 'passed';
    case Failed = 'failed';
    case Conditional = 'conditional';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Failed and conditional inspections can raise NCRs. */
    public function needsFollowUp(): bool
    {
        return $this !== self::Passed;
    }
}
