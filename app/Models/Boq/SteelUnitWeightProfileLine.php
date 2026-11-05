<?php

namespace App\Models\Boq;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SteelUnitWeightProfileLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'diameter_mm' => 'integer',
            'weight_kg_per_m' => 'decimal:4',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SteelUnitWeightProfile::class, 'profile_id');
    }
}
