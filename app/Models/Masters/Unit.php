<?php

namespace App\Models\Masters;

use App\Models\Boq\BoqItem;
use App\Models\Boq\RateAnalysis;
use App\Models\Boq\RateAnalysisItem;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'symbol', 'decimal_places', 'is_active'])]
class Unit extends MasterModel
{
    protected array $searchable = ['name', 'symbol'];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return Material::query()->where('unit_id', $this->id)->exists()
            || BoqItem::query()->where('unit_id', $this->id)->exists()
            || RateAnalysis::query()->where('unit_id', $this->id)->exists()
            || RateAnalysisItem::query()->where('unit_id', $this->id)->exists()
            || ProjectTask::query()->withTrashed()->where('unit_id', $this->id)->exists();
    }
}
