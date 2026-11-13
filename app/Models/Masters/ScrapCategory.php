<?php

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'code', 'name', 'description', 'sort_order', 'is_active'])]
class ScrapCategory extends MasterModel
{
    protected $table = 'scrap_categories';

    protected array $searchable = ['code', 'name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ScrapType, $this>
     */
    public function types(): HasMany
    {
        return $this->hasMany(ScrapType::class)->orderBy('sort_order')->orderBy('name');
    }

    public function isInUse(): bool
    {
        return $this->types()->exists();
    }
}
