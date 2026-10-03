<?php

namespace App\Models\Subcontract;

use App\Enums\Subcontract\MilestoneStatus;
use App\Models\Concerns\LockedByParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Financial milestone of a work order (share of the order value). Defined while the order is a
 * draft; only its achievement (status / achieved_at) is recorded after approval.
 */
class WorkOrderMilestone extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = WorkOrder::class;

    public const PARENT_KEY = 'work_order_id';

    public const POSTING_COLUMNS = ['status', 'achieved_at', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'due_date' => 'date',
            'amount_percent' => 'decimal:4',
            'achieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
