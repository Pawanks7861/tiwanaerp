<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Open low-stock alert (deduplication marker): present while a warehouse + material stays at or
 * below its reorder level, removed when stock recovers.
 */
class LowStockAlert extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'alerted_at' => 'datetime',
        ];
    }
}
