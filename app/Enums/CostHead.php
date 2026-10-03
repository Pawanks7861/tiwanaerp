<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CostHead: string
{
    use HasOptions;

    case Material = 'material';
    case Labour = 'labour';
    case Equipment = 'equipment';
    case Subcontract = 'subcontract';
    case Overhead = 'overhead';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
