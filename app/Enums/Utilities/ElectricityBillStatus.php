<?php

namespace App\Enums\Utilities;

enum ElectricityBillStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case ActualEntered = 'actual_entered';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculated => 'Calculated',
            self::ActualEntered => 'Actual entered',
            self::Reviewed => 'Reviewed',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Calculated, self::ActualEntered], true);
    }

    public function isImmutable(): bool
    {
        return $this === self::Approved;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
