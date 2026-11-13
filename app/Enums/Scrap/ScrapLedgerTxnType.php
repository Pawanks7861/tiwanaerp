<?php

namespace App\Enums\Scrap;

use App\Enums\Concerns\HasOptions;

enum ScrapLedgerTxnType: string
{
    use HasOptions;

    case GenerationIn = 'generation_in';
    case GatePassOut = 'gate_pass_out';
    case GatePassReturnIn = 'gate_pass_return_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::GenerationIn => 'Scrap generation',
            self::GatePassOut => 'Gate pass (out)',
            self::GatePassReturnIn => 'Gate pass return',
            self::AdjustmentIn => 'Adjustment (in)',
            self::AdjustmentOut => 'Adjustment (out)',
            self::Reversal => 'Reversal',
        };
    }

    public function isIncoming(): bool
    {
        return in_array($this, [self::GenerationIn, self::GatePassReturnIn, self::AdjustmentIn], true);
    }
}
