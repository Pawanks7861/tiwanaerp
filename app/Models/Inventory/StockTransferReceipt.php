<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One (possibly partial) receipt of a dispatched transfer. Receipts are append-only.
 */
class StockTransferReceipt extends Model
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
            'receipt_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return HasMany<StockTransferReceiptItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
