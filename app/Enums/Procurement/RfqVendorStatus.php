<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum RfqVendorStatus: string
{
    use HasOptions;

    case Invited = 'invited';
    case Sent = 'sent';
    case Responded = 'responded';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
