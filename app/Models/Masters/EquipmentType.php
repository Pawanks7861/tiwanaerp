<?php

namespace App\Models\Masters;

use App\Models\Boq\RateAnalysisItem;
use App\Models\Equipment\Equipment;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'is_active'])]
class EquipmentType extends MasterModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return RateAnalysisItem::query()->where('equipment_type_id', $this->id)->exists()
            || Equipment::query()->withTrashed()->where('equipment_type_id', $this->id)->exists();
    }
}
