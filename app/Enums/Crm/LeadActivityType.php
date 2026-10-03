<?php

namespace App\Enums\Crm;

use App\Enums\Concerns\HasOptions;

enum LeadActivityType: string
{
    use HasOptions;

    case Call = 'call';
    case Visit = 'visit';
    case Email = 'email';
    case Note = 'note';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
