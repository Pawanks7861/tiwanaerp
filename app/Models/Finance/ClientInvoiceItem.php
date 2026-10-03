<?php

namespace App\Models\Finance;

use App\Models\Boq\BoqItem;
use App\Models\Concerns\Auditable;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Measured line of an RA bill, keyed by the BOQ line_uid so it survives BOQ revisions. The rate is
 * the BOQ client rate snapshotted when the line is written.
 */
class ClientInvoiceItem extends Model
{
    use Auditable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = fn (self $item) => ClientInvoice::query()->withoutGlobalScopes()->withTrashed()
            ->findOrFail($item->client_invoice_id)->assertEditable();

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'boq_qty' => 'decimal:4',
            'executed_qty' => 'decimal:4',
            'previous_qty' => 'decimal:4',
            'current_qty' => 'decimal:4',
            'cumulative_qty' => 'decimal:4',
            'rate' => 'decimal:4',
            'current_amount' => 'decimal:2',
            'is_override' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ClientInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id')->withTrashed();
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
