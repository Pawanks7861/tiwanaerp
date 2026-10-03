<?php

namespace App\Models\SiteExecution;

use App\Models\Boq\BoqItem;
use App\Models\Concerns\LockedByParent;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work line of a DPR. planned / cumulative / balance are server snapshots (refreshed while the
 * DPR is a draft, frozen at approval); executed_qty is what approval posts to the ledger.
 */
class DprItem extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = Dpr::class;

    public const PARENT_KEY = 'dpr_id';

    public const POSTING_COLUMNS = ['planned_qty', 'cumulative_qty', 'balance_qty', 'boq_line_uid', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:4',
            'executed_qty' => 'decimal:4',
            'cumulative_qty' => 'decimal:4',
            'balance_qty' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dpr, $this>
     */
    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
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
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }
}
