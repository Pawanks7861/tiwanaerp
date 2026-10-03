<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BudgetStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
