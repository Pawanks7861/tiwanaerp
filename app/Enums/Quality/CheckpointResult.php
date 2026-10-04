<?php

namespace App\Enums\Quality;

use App\Enums\Concerns\HasOptions;

enum CheckpointResult: string
{
    use HasOptions;

    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'na';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::NotApplicable => 'N/A',
        };
    }
}
