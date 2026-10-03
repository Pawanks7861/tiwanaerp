<?php

namespace App\Enums\Labour;

use App\Enums\Concerns\HasOptions;

/**
 * Day status of a labourer. Wage rule (company rule, see section S): present = full daily wage,
 * half day = half, absent and leave = unpaid. Overtime only on present / half days.
 */
enum AttendanceStatus: string
{
    use HasOptions;

    case Present = 'present';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::HalfDay => 'Half day',
            default => ucfirst($this->value),
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::HalfDay => 'H',
            self::Absent => 'A',
            self::Leave => 'L',
        };
    }

    public function isWorking(): bool
    {
        return $this === self::Present || $this === self::HalfDay;
    }

    /** Share of the daily wage earned: "1", "0.5" or "0". */
    public function wageFactor(): string
    {
        return match ($this) {
            self::Present => '1',
            self::HalfDay => '0.5',
            default => '0',
        };
    }

    /** Hours assumed when no punch times or hours are given. */
    public function defaultHours(): string
    {
        return match ($this) {
            self::Present => '8',
            self::HalfDay => '4',
            default => '0',
        };
    }
}
