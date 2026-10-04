<?php

namespace App\Models\Quality;

use App\Enums\Discipline;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company checklist template. Inspections copy its checkpoints when created, so editing a template
 * never rewrites an existing inspection. A template already used by an inspection is deactivated,
 * never deleted.
 */
class QualityChecklist extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('activity', 'like', $like));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isInUse(): bool
    {
        return QualityInspection::query()->withoutGlobalScopes()->withTrashed()->where('quality_checklist_id', $this->id)->exists();
    }

    /**
     * @return HasMany<QualityChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QualityChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
