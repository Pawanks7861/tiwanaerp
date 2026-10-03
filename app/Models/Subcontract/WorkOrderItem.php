<?php

namespace App\Models\Subcontract;

use App\Models\Boq\BoqItem;
use App\Models\Concerns\LockedByParent;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Work order line, linked to a BOQ line (boq_line_uid survives BOQ revisions). certified_qty is a
 * cache recomputed from certified bills (SubcontractorBillService), never incremented.
 */
class WorkOrderItem extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = WorkOrder::class;

    public const PARENT_KEY = 'work_order_id';

    public const POSTING_COLUMNS = ['certified_qty', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'certified_qty' => 'decimal:4',
        ];
    }

    public function balanceQty(): Decimal
    {
        return Decimal::of($this->quantity)->minus($this->certified_qty);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
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
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * @return HasMany<SubcontractorBillItem, $this>
     */
    public function billItems(): HasMany
    {
        return $this->hasMany(SubcontractorBillItem::class);
    }
}
