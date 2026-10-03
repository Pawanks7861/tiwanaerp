<?php

namespace App\Enums\Numbering;

use App\Enums\Concerns\HasOptions;

enum ResetFrequency: string
{
    use HasOptions;

    case Never = 'never';
    case Yearly = 'yearly';
    case FinancialYear = 'financial_year';
    case Monthly = 'monthly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never',
            self::Yearly => 'Every calendar year',
            self::FinancialYear => 'Every financial year',
            self::Monthly => 'Every month',
            self::Daily => 'Every day',
        };
    }
}
