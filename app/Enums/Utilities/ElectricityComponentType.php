<?php

namespace App\Enums\Utilities;

enum ElectricityComponentType: string
{
    case Energy = 'energy';
    case Fixed = 'fixed';
    case Demand = 'demand';
    case DemandSurcharge = 'demand_surcharge';
    case Wheeling = 'wheeling';
    case Fuel = 'fuel';
    case Fppca = 'fppca';
    case Duty = 'duty';
    case Cess = 'cess';
    case MunicipalTax = 'municipal_tax';
    case TodPeak = 'tod_peak';
    case TodRebate = 'tod_rebate';
    case PfRebate = 'pf_rebate';
    case EhvRebate = 'ehv_rebate';
    case BulkRebate = 'bulk_rebate';
    case Subsidy = 'subsidy';
    case Penalty = 'penalty';
    case MeterRent = 'meter_rent';
    case OtherRent = 'other_rent';
    case Tcs = 'tcs';
    case ManualAdjustment = 'manual_adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Energy => 'Energy charge',
            self::Fixed => 'Fixed charge',
            self::Demand => 'Demand charge',
            self::DemandSurcharge => 'Demand surcharge',
            self::Wheeling => 'Wheeling charge',
            self::Fuel => 'FAC / fuel charge',
            self::Fppca => 'FPPCA',
            self::Duty => 'Electricity duty',
            self::Cess => 'Green / other cess',
            self::MunicipalTax => 'Municipal tax',
            self::TodPeak => 'TOD peak charge',
            self::TodRebate => 'TOD rebate',
            self::PfRebate => 'PF rebate / penalty',
            self::EhvRebate => 'EHV rebate',
            self::BulkRebate => 'Bulk consumer rebate',
            self::Subsidy => 'Subsidy',
            self::Penalty => 'Penalty / PLEC',
            self::MeterRent => 'Meter rent',
            self::OtherRent => 'Other rent',
            self::Tcs => 'TCS',
            self::ManualAdjustment => 'Manual adjustment',
            self::Other => 'Other',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
