<?php

namespace App\Enums\Equipment;

enum EquipmentQuantityMovementType: string
{
    case Opening = 'opening';
    case Add = 'add';
    case Remove = 'remove';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case GateOut = 'gate_out';
    case GateIn = 'gate_in';
    case Retired = 'retired';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening',
            self::Add => 'Add',
            self::Remove => 'Remove',
            self::AdjustmentIn => 'Adjustment in',
            self::AdjustmentOut => 'Adjustment out',
            self::GateOut => 'Gate out',
            self::GateIn => 'Gate in',
            self::Retired => 'Retired',
            self::Returned => 'Returned',
        };
    }

    public function signedQuantity(string $quantity): string
    {
        $positive = in_array($this, [
            self::Opening,
            self::Add,
            self::AdjustmentIn,
            self::GateIn,
            self::Returned,
        ], true);

        return $positive ? $quantity : '-'.$quantity;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return [
            ['value' => self::Add->value, 'label' => self::Add->label()],
            ['value' => self::Remove->value, 'label' => self::Remove->label()],
            ['value' => self::AdjustmentIn->value, 'label' => self::AdjustmentIn->label()],
            ['value' => self::AdjustmentOut->value, 'label' => self::AdjustmentOut->label()],
            ['value' => self::Retired->value, 'label' => self::Retired->label()],
            ['value' => self::Returned->value, 'label' => self::Returned->label()],
        ];
    }
}
