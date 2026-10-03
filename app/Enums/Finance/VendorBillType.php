<?php

namespace App\Enums\Finance;

use App\Enums\Concerns\HasOptions;

/**
 * purchase_order: goods received on a PO (3-way match). The material cost reaches the project
 * when stock is issued, so the bill posts no cost. direct: non-stock goods or services billed
 * without a PO, explicitly classified with a cost head; approval posts that cost.
 */
enum VendorBillType: string
{
    use HasOptions;

    case PurchaseOrder = 'purchase_order';
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'Against PO / GRN',
            self::Direct => 'Direct (non-stock / service)',
        };
    }
}
