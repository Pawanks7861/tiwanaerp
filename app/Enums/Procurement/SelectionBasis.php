<?php

namespace App\Enums\Procurement;

use App\Enums\Concerns\HasOptions;

enum SelectionBasis: string
{
    use HasOptions;

    case LowestPrice = 'lowest_price';
    case BestDelivery = 'best_delivery';
    case BestTerms = 'best_terms';
    case Quality = 'quality';
    case Technical = 'technical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LowestPrice => 'Lowest price',
            self::BestDelivery => 'Best delivery',
            self::BestTerms => 'Best commercial terms',
            self::Quality => 'Quality / brand',
            self::Technical => 'Technical compliance',
            self::Other => 'Other',
        };
    }
}
