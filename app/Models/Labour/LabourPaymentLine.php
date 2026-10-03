<?php

namespace App\Models\Labour;

use App\Models\Concerns\LockedByParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-labourer total of a payment batch. Days, gross and OT come from the linked attendance;
 * only advance_recovery / other_deductions are entered, and only while the batch is a draft.
 */
class LabourPaymentLine extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = LabourPayment::class;

    public const PARENT_KEY = 'labour_payment_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'present_days' => 'decimal:1',
            'half_days' => 'decimal:1',
            'ot_hours' => 'decimal:2',
            'gross_wage' => 'decimal:2',
            'ot_amount' => 'decimal:2',
            'advance_recovery' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LabourPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(LabourPayment::class, 'labour_payment_id');
    }

    /**
     * @return BelongsTo<Labour, $this>
     */
    public function labour(): BelongsTo
    {
        return $this->belongsTo(Labour::class)->withTrashed();
    }
}
