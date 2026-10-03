<?php

namespace App\Enums\Inventory;

use App\Enums\Concerns\HasOptions;

enum StockTxnType: string
{
    use HasOptions;

    case Opening = 'opening';
    case GrnIn = 'grn_in';
    case IssueOut = 'issue_out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case ReturnIn = 'return_in';
    case ReturnToVendorOut = 'return_to_vendor_out';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening stock',
            self::GrnIn => 'GRN receipt',
            self::IssueOut => 'Issue to site',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::ReturnIn => 'Site return',
            self::ReturnToVendorOut => 'Return to vendor',
            self::AdjustmentIn => 'Adjustment (gain)',
            self::AdjustmentOut => 'Adjustment (loss)',
            self::Reversal => 'Reversal',
        };
    }

    /**
     * Direction of a forward movement; reversals take the opposite direction of the row they cancel.
     */
    public function isIncoming(): bool
    {
        return in_array($this, [self::Opening, self::GrnIn, self::TransferIn, self::ReturnIn, self::AdjustmentIn], true);
    }
}
