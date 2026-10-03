<?php

namespace App\Models\Masters;

use App\Enums\CostHead;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'cost_head', 'is_active'])]
class ExpenseCategory extends MasterModel
{
    protected function casts(): array
    {
        return [
            'cost_head' => CostHead::class,
            'is_active' => 'boolean',
        ];
    }
}
