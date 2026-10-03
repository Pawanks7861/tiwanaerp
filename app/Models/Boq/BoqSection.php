<?php

namespace App\Models\Boq;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BOQ section or subsection (two levels). Tenant isolation comes through the parent BOQ.
 */
#[Fillable(['parent_id', 'code', 'name', 'discipline', 'sort_order'])]
class BoqSection extends Model
{
    protected static function booted(): void
    {
        $guard = function (self $section) {
            $boq = $section->relationLoaded('boq') && $section->boq?->id === $section->boq_id
                ? $section->boq
                : Boq::query()->withoutGlobalScopes()->find($section->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<BoqItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }
}
