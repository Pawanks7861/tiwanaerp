<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum RequestPriority: string
{
    use HasOptions;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
