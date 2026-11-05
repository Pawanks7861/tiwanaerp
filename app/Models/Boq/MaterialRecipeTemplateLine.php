<?php

namespace App\Models\Boq;

use App\Models\Masters\Material;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRecipeTemplateLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'consumption_qty' => 'decimal:4',
            'wastage_percent' => 'decimal:4',
            'sequence' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(MaterialRecipeTemplate::class, 'recipe_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
