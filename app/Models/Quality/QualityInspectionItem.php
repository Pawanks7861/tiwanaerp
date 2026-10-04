<?php

namespace App\Models\Quality;

use App\Enums\Quality\CheckpointResult;
use App\Models\Concerns\LockedByParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint copied from the checklist template when the inspection was created; result and remark
 * are recorded by the inspector and frozen when the inspection completes.
 */
class QualityInspectionItem extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = QualityInspection::class;

    public const PARENT_KEY = 'quality_inspection_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'result' => CheckpointResult::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QualityInspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityInspection::class, 'quality_inspection_id');
    }

    /**
     * @return BelongsTo<QualityChecklistItem, $this>
     */
    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(QualityChecklistItem::class, 'quality_checklist_item_id');
    }
}
