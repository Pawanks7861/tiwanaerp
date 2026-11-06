<?php

namespace App\Enums\Gate;

use App\Enums\Concerns\HasOptions;

enum GatePassStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Verified = 'verified';
    case Closed = 'closed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
