<?php

namespace App\Enums\Quality;

use App\Enums\Concerns\HasOptions;

enum NcrSeverity: string
{
    use HasOptions;

    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
