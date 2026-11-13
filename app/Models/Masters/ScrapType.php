<?php

namespace App\Models\Masters;

use App\Models\Gate\GatePassItem;
use App\Models\Scrap\ScrapGenerationItem;
use App\Models\Scrap\ScrapLedgerEntry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'scrap_category_id', 'code', 'name', 'unit_id', 'description', 'sort_order', 'is_active'])]
class ScrapType extends MasterModel
{
    protected $table = 'scrap_types';

    protected array $searchable = ['code', 'name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ScrapCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ScrapCategory::class, 'scrap_category_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasMany<ScrapGenerationItem, $this>
     */
    public function generationItems(): HasMany
    {
        return $this->hasMany(ScrapGenerationItem::class);
    }

    /**
     * @return HasMany<ScrapLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(ScrapLedgerEntry::class);
    }

    /**
     * @return HasMany<GatePassItem, $this>
     */
    public function gatePassItems(): HasMany
    {
        return $this->hasMany(GatePassItem::class);
    }

    public function isInUse(): bool
    {
        return $this->generationItems()->exists()
            || $this->ledgerEntries()->exists()
            || $this->gatePassItems()->exists();
    }
}
