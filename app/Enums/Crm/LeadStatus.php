<?php

namespace App\Enums\Crm;

use App\Enums\Concerns\HasOptions;

enum LeadStatus: string
{
    use HasOptions;

    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Quoted = 'quoted';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
