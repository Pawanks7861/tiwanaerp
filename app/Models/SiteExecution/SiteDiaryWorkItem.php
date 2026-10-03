<?php

namespace App\Models\SiteExecution;

use App\Models\Boq\BoqItem;
use App\Models\Concerns\LockedByParent;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDiaryWorkItem extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = SiteDiary::class;

    public const PARENT_KEY = 'site_diary_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<SiteDiary, $this>
     */
    public function diary(): BelongsTo
    {
        return $this->belongsTo(SiteDiary::class, 'site_diary_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}
