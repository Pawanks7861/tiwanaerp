<?php

namespace App\Models\Boq;

use App\Models\Concerns\Blameable;
use App\Models\Masters\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqBbsSteelMap extends Model
{
    use Blameable;

    protected $table = 'boq_bbs_steel_maps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'diameter_mm' => 'integer',
        ];
    }

    public function bbs(): BelongsTo
    {
        return $this->belongsTo(BoqBbs::class, 'bbs_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
