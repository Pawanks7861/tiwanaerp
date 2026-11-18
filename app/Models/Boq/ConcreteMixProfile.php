<?php

namespace App\Models\Boq;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConcreteMixProfile extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ConcreteMixProfileLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ConcreteMixProfileLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
