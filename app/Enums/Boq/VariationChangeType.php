<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum VariationChangeType: string
{
    use HasOptions;

    case QuantityIncrease = 'quantity_increase';
    case QuantityDecrease = 'quantity_decrease';
    case NewItem = 'new_item';
    case OmittedItem = 'omitted_item';
    case RateChange = 'rate_change';
    case ScopeChange = 'scope_change';

    public function label(): string
    {
        return match ($this) {
            self::QuantityIncrease => 'Quantity increase',
            self::QuantityDecrease => 'Quantity decrease',
            self::NewItem => 'New / extra item',
            self::OmittedItem => 'Omitted item',
            self::RateChange => 'Rate change',
            self::ScopeChange => 'Scope change',
        };
    }

    public function affectsQuantity(): bool
    {
        return in_array($this, [
            self::QuantityIncrease,
            self::QuantityDecrease,
            self::NewItem,
            self::OmittedItem,
            self::ScopeChange,
        ], true);
    }
}
