<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Received quantity of one transfer line within a receipt; the source of its transfer_in posting.
 */
class StockTransferReceiptItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = function () {
            throw new LogicException('Transfer receipts are append-only.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StockTransferReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceipt::class, 'stock_transfer_receipt_id');
    }

    /**
     * @return BelongsTo<StockTransferItem, $this>
     */
    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }
}
