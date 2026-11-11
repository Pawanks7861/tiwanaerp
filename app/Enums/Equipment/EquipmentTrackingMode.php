<?php

namespace App\Enums\Equipment;

enum EquipmentTrackingMode: string
{
    case Individual = 'individual';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual asset',
            self::Quantity => 'Quantity-based',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
