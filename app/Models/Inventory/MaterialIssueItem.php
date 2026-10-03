<?php

namespace App\Models\Inventory;

use App\Models\Boq\BoqItem;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue line. unit_cost / amount are written once, by MaterialIssueService at posting.
 */
class MaterialIssueItem extends Model
{
    public const POSTING_COLUMNS = ['unit_cost', 'amount', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $parent = fn (self $item) => MaterialIssue::query()->withoutGlobalScopes()->findOrFail($item->material_issue_id);

        static::saving(function (self $item) use ($parent) {
            $issue = $parent($item);
            $postingOnly = $item->exists && array_diff(array_keys($item->getDirty()), self::POSTING_COLUMNS) === [];

            if (! $issue->isEditable() && ! $postingOnly) {
                throw $issue->lockedException();
            }
        });

        static::deleting(fn (self $item) => $parent($item)->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<MaterialIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return HasMany<MaterialReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(MaterialReturnItem::class);
    }
}
