<?php

namespace App\Models\Masters;

use App\Models\Boq\RateAnalysisItem;
use App\Models\Labour\Labour;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'default_daily_wage', 'is_active'])]
class LabourTrade extends MasterModel
{
    protected function casts(): array
    {
        return [
            'default_daily_wage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return RateAnalysisItem::query()->where('labour_trade_id', $this->id)->exists()
            || Labour::query()->withTrashed()->where('labour_trade_id', $this->id)->exists();
    }
}
