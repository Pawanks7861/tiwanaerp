<?php

namespace App\Enums\Utilities;

enum ElectricityCalculationBasis: string
{
    case Kwh = 'kwh';
    case Kvah = 'kvah';
    case BillingDemand = 'billing_demand';
    case ContractDemand = 'contract_demand';
    case SanctionedDemand = 'sanctioned_demand';
    case ExcessDemand = 'excess_demand';
    case PercentEnergyCharge = 'percent_energy_charge';
    case PercentSubtotal = 'percent_subtotal';
    case FixedAmount = 'fixed_amount';
    case TodSlotUnits = 'tod_slot_units';
    case PowerFactorRule = 'power_factor_rule';
    case Manual = 'manual';
    case Slab = 'slab';

    public function label(): string
    {
        return match ($this) {
            self::Kwh => 'kWh',
            self::Kvah => 'kVAh',
            self::BillingDemand => 'Billing demand',
            self::ContractDemand => 'Contract demand',
            self::SanctionedDemand => 'Sanctioned demand',
            self::ExcessDemand => 'Excess demand',
            self::PercentEnergyCharge => '% of energy charge',
            self::PercentSubtotal => '% of subtotal',
            self::FixedAmount => 'Fixed amount',
            self::TodSlotUnits => 'TOD slot units',
            self::PowerFactorRule => 'Power factor rule',
            self::Manual => 'Manual',
            self::Slab => 'Slab',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
