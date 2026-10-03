<?php

namespace App\Models\Procurement;

use App\Enums\Procurement\RfqVendorStatus;
use App\Models\Masters\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vendor invited to an RFQ. Rows are created and updated only by RfqService / VendorQuotationService.
 */
class RfqVendor extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => RfqVendorStatus::class,
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
