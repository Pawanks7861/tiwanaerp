<?php

namespace App\Models\Subcontract;

use App\Enums\Subcontract\SubcontractorBillStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Bill line against a work order item: previous (certified on earlier bills) + certified this
 * bill = cumulative. Fully editable in a draft; while submitted only the certification columns
 * may change; frozen once certified.
 */
class SubcontractorBillItem extends Model
{
    public const CERTIFICATION_COLUMNS = ['previous_qty', 'certified_qty', 'cumulative_qty', 'amount', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $bill = fn (self $item) => SubcontractorBill::query()->withoutGlobalScopes()->findOrFail($item->subcontractor_bill_id);

        static::saving(function (self $item) use ($bill) {
            $parent = $bill($item);
            if ($parent->status->isEditable()) {
                return;
            }
            $certificationOnly = $item->exists && array_diff(array_keys($item->getDirty()), self::CERTIFICATION_COLUMNS) === [];
            if ($parent->status !== SubcontractorBillStatus::Submitted || ! $certificationOnly) {
                throw ValidationException::withMessages(['bill' => $parent->lockedMessage()]);
            }
        });

        static::deleting(fn (self $item) => $bill($item)->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'wo_qty' => 'decimal:4',
            'previous_qty' => 'decimal:4',
            'claimed_qty' => 'decimal:4',
            'certified_qty' => 'decimal:4',
            'cumulative_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SubcontractorBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(SubcontractorBill::class, 'subcontractor_bill_id');
    }

    /**
     * @return BelongsTo<WorkOrderItem, $this>
     */
    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }
}
