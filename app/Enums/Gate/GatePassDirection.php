<?php

namespace App\Enums\Gate;

use App\Enums\Concerns\HasOptions;

enum GatePassDirection: string
{
    use HasOptions;

    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
