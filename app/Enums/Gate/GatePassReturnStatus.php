<?php

namespace App\Enums\Gate;

use App\Enums\Concerns\HasOptions;

enum GatePassReturnStatus: string
{
    use HasOptions;

    case Recorded = 'recorded';
    case Verified = 'verified';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
