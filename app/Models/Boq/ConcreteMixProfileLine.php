<?php

namespace App\Models\Boq;

use App\Models\Masters\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConcreteMixProfileLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ConcreteMixProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ConcreteMixProfile::class, 'concrete_mix_profile_id');
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
