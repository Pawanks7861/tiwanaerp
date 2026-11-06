<?php

namespace App\Enums\Gate;

use App\Enums\Concerns\HasOptions;

enum GatePassItemType: string
{
    use HasOptions;

    case Material = 'material';
    case Steel = 'steel';
    case Scrap = 'scrap';
    case Equipment = 'equipment';
    case Tool = 'tool';
    case Vehicle = 'vehicle';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
