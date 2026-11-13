<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityCalculationBasis;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\ElectricityTariff;
use App\Models\Utilities\ElectricityTariffComponent;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Whitelisted tariff component evaluator. No eval. Snapshot-ready breakdown.
 */
final class ElectricityBillCalculationService
{
    public function __construct(private readonly ElectricityTariffResolver $tariffs) {}

    /**
     * @param  Collection<int, array{slot_code: string, units: string}>|null  $todUnits
     * @return array{
     *   tariff: ElectricityTariff,
     *   components: list<array<string, mixed>>,
     *   calculated_total: string,
     *   cost_per_kwh: ?string,
     *   cost_per_kvah: ?string,
     *   demand_utilization_percent: ?string,
     *   excess_demand: ?string,
     *   tariff_snapshot: array<string, mixed>,
     *   reading_snapshot: array<string, mixed>
     * }
     */
    public function calculate(ElectricityMeterReading $reading, ?ElectricityTariff $tariff = null, ?Collection $todUnits = null): array
    {
        $reading->loadMissing(['connection', 'todReadings', 'site']);
        $connection = $reading->connection
            ?? throw ValidationException::withMessages(['reading' => 'Meter reading has no connection.']);

        $tariff ??= $this->tariffs->forPeriod($connection, $reading->billing_month);
        $tariff->loadMissing('components');

        $ctx = $this->context($reading, $connection, $todUnits);
        $energyCharge = Decimal::zero();
        $subtotal = Decimal::zero();
        $lines = [];
        $sort = 0;

        $active = $tariff->components->filter(fn (ElectricityTariffComponent $c) => $c->is_active)->values();

        // Pass 1: non-percent bases (except percent-of-subtotal deferred)
        foreach ($active as $component) {
            if (in_array($component->calculation_basis, [ElectricityCalculationBasis::PercentSubtotal], true)) {
                continue;
            }
            $line = $this->evaluate($component, $ctx, $energyCharge, $subtotal);
            if ($component->component_type === \App\Enums\Utilities\ElectricityComponentType::Energy) {
                $energyCharge = $energyCharge->plus($line['calculated_amount']);
            }
            $signed = $this->signedAmount($component, Decimal::of($line['calculated_amount']));
            $subtotal = $subtotal->plus($signed);
            $line['sort_order'] = $sort++;
            $lines[] = $line;
        }

        // Pass 2: percent of subtotal (using running subtotal before these lines)
        $baseSubtotal = $subtotal;
        foreach ($active as $component) {
            if ($component->calculation_basis !== ElectricityCalculationBasis::PercentSubtotal) {
                continue;
            }
            $line = $this->evaluate($component, $ctx, $energyCharge, $baseSubtotal);
            $signed = $this->signedAmount($component, Decimal::of($line['calculated_amount']));
            $subtotal = $subtotal->plus($signed);
            $line['sort_order'] = $sort++;
            $lines[] = $line;
        }

        $total = $subtotal->toMoney();
        $kwh = Decimal::of($ctx['kwh']);
        $kvah = $ctx['kvah'] !== null ? Decimal::of($ctx['kvah']) : null;

        return [
            'tariff' => $tariff,
            'components' => $lines,
            'calculated_total' => $total,
            'cost_per_kwh' => $kwh->isZero() ? null : Decimal::of($total)->dividedBy($kwh, 4)->toRate(),
            'cost_per_kvah' => ($kvah === null || $kvah->isZero()) ? null : Decimal::of($total)->dividedBy($kvah, 4)->toRate(),
            'demand_utilization_percent' => $ctx['demand_utilization_percent'],
            'excess_demand' => $ctx['excess_demand'],
            'tariff_snapshot' => [
                'id' => $tariff->id,
                'name' => $tariff->name,
                'effective_from' => $tariff->effective_from?->toDateString(),
                'effective_to' => $tariff->effective_to?->toDateString(),
                'components' => $active->map(fn (ElectricityTariffComponent $c) => $c->only([
                    'id', 'component_code', 'component_name', 'component_type', 'calculation_basis',
                    'rate', 'percentage', 'slab_config', 'tod_slot_config', 'formula_config', 'unit',
                    'sort_order', 'is_rebate', 'is_tax', 'is_penalty', 'is_manual_adjustment',
                ]))->values()->all(),
            ],
            'reading_snapshot' => [
                'id' => $reading->id,
                'billing_month' => $reading->billing_month?->toDateString(),
                'kwh_consumption' => (string) $reading->kwh_consumption,
                'kvah_consumption' => $reading->kvah_consumption !== null ? (string) $reading->kvah_consumption : null,
                'billing_demand' => $reading->billing_demand !== null ? (string) $reading->billing_demand : null,
                'power_factor' => $reading->power_factor !== null ? (string) $reading->power_factor : null,
                'multiplication_factor' => (string) $reading->multiplication_factor,
            ],
        ];
    }

    /**
     * @param  Collection<int, array{slot_code: string, units: string}>|null  $todUnits
     * @return array<string, mixed>
     */
    private function context(ElectricityMeterReading $reading, ElectricityConnection $connection, ?Collection $todUnits): array
    {
        $contract = Decimal::of($connection->contract_demand ?? '0');
        $sanctioned = Decimal::of($connection->sanctioned_demand ?? $connection->contract_demand ?? '0');
        $allowed = $contract->greaterThan('0') ? $contract : $sanctioned;
        $billingDemand = $reading->billing_demand !== null ? Decimal::of($reading->billing_demand) : Decimal::zero();
        $excess = $billingDemand->greaterThan($allowed) ? $billingDemand->minus($allowed)->toQuantity() : '0';
        $utilization = $allowed->isZero()
            ? null
            : $billingDemand->times(100)->dividedBy($allowed, 4)->toRate();

        $slots = [];
        foreach ($todUnits ?? $reading->todReadings as $row) {
            $code = is_array($row) ? (string) $row['slot_code'] : (string) $row->slot_code;
            $units = is_array($row) ? (string) $row['units'] : (string) $row->units;
            $slots[$code] = Decimal::of($units);
        }

        return [
            'kwh' => (string) $reading->kwh_consumption,
            'kvah' => $reading->kvah_consumption !== null ? (string) $reading->kvah_consumption : null,
            'billing_demand' => $reading->billing_demand !== null ? (string) $reading->billing_demand : '0',
            'contract_demand' => $contract->toQuantity(),
            'sanctioned_demand' => $sanctioned->toQuantity(),
            'excess_demand' => $excess,
            'demand_utilization_percent' => $utilization,
            'power_factor' => $reading->power_factor !== null ? (string) $reading->power_factor : null,
            'low_pf_threshold' => $connection->low_pf_threshold !== null ? (string) $connection->low_pf_threshold : null,
            'tod_slots' => $slots,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function evaluate(ElectricityTariffComponent $component, array $ctx, Decimal $energyCharge, Decimal $subtotal): array
    {
        $basis = $component->calculation_basis;
        $rate = Decimal::of($component->rate ?? '0');
        $percent = Decimal::of($component->percentage ?? '0');
        $qty = Decimal::zero();
        $amount = Decimal::zero();

        switch ($basis) {
            case ElectricityCalculationBasis::Kwh:
                $qty = Decimal::of($ctx['kwh']);
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::Kvah:
                $qty = Decimal::of($ctx['kvah'] ?? '0');
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::BillingDemand:
                $qty = Decimal::of($ctx['billing_demand']);
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::ContractDemand:
                $qty = Decimal::of($ctx['contract_demand']);
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::SanctionedDemand:
                $qty = Decimal::of($ctx['sanctioned_demand']);
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::ExcessDemand:
                $qty = Decimal::of($ctx['excess_demand']);
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::PercentEnergyCharge:
                $qty = $energyCharge;
                $amount = $energyCharge->percentOf($percent);
                break;
            case ElectricityCalculationBasis::PercentSubtotal:
                $qty = $subtotal;
                $amount = $subtotal->percentOf($percent);
                break;
            case ElectricityCalculationBasis::FixedAmount:
                $qty = Decimal::of('1');
                $amount = $rate;
                break;
            case ElectricityCalculationBasis::TodSlotUnits:
                $slot = (string) (($component->tod_slot_config['slot_code'] ?? '') ?: '');
                $qty = $ctx['tod_slots'][$slot] ?? Decimal::zero();
                $amount = $qty->times($rate);
                break;
            case ElectricityCalculationBasis::PowerFactorRule:
                [$qty, $amount] = $this->powerFactorAmount($component, $ctx, $energyCharge);
                break;
            case ElectricityCalculationBasis::Slab:
                [$qty, $amount] = $this->slabAmount($component, $ctx);
                break;
            case ElectricityCalculationBasis::Manual:
                $qty = Decimal::zero();
                $amount = Decimal::zero();
                break;
            default:
                throw ValidationException::withMessages([
                    'tariff' => 'Unsupported calculation basis: '.$basis->value,
                ]);
        }

        return [
            'tariff_component_id' => $component->id,
            'component_code' => $component->component_code,
            'component_name' => $component->component_name,
            'calculation_basis' => $basis->value,
            'quantity' => $qty->toQuantity(),
            'rate' => $rate->toRate(),
            'percentage' => $percent->round(4)->toString(),
            'calculated_amount' => $amount->toMoney(),
            'is_rebate' => (bool) $component->is_rebate,
            'is_tax' => (bool) $component->is_tax,
            'is_penalty' => (bool) $component->is_penalty,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{0: Decimal, 1: Decimal}
     */
    private function powerFactorAmount(ElectricityTariffComponent $component, array $ctx, Decimal $energyCharge): array
    {
        $pf = $ctx['power_factor'] !== null ? Decimal::of($ctx['power_factor']) : null;
        if ($pf === null) {
            return [Decimal::zero(), Decimal::zero()];
        }

        $config = $component->formula_config ?? [];
        $threshold = Decimal::of($config['threshold'] ?? $ctx['low_pf_threshold'] ?? '0.9');
        $mode = (string) ($config['mode'] ?? 'rebate_if_above');
        $capPercent = isset($config['cap_percent']) ? Decimal::of($config['cap_percent']) : null;
        $rate = Decimal::of($component->rate ?? '0');
        $percent = Decimal::of($component->percentage ?? '0');

        $applies = match ($mode) {
            'rebate_if_above' => $pf->greaterThanOrEqual($threshold),
            'penalty_if_below' => $pf->lessThan($threshold),
            default => false,
        };

        if (! $applies) {
            return [Decimal::zero(), Decimal::zero()];
        }

        $amount = ! $percent->isZero()
            ? $energyCharge->percentOf($percent)
            : $rate;

        if ($capPercent !== null) {
            $cap = $energyCharge->percentOf($capPercent);
            if ($amount->greaterThan($cap)) {
                $amount = $cap;
            }
        }

        return [$pf, $amount];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{0: Decimal, 1: Decimal}
     */
    private function slabAmount(ElectricityTariffComponent $component, array $ctx): array
    {
        $basisQtyKey = (string) (($component->slab_config['quantity_basis'] ?? 'billing_demand'));
        $qty = Decimal::of($ctx[$basisQtyKey] ?? $ctx['billing_demand'] ?? '0');
        $slabs = $component->slab_config['slabs'] ?? [];
        if (! is_array($slabs) || $slabs === []) {
            return [$qty, Decimal::zero()];
        }

        $remaining = $qty;
        $amount = Decimal::zero();
        $cursor = Decimal::zero();

        foreach ($slabs as $slab) {
            $from = Decimal::of($slab['from'] ?? '0');
            $to = isset($slab['to']) && $slab['to'] !== null && $slab['to'] !== ''
                ? Decimal::of($slab['to'])
                : null;
            $slabRate = Decimal::of($slab['rate'] ?? '0');

            if ($qty->lessThanOrEqual($from)) {
                continue;
            }

            $upper = $to ?? $qty;
            if ($upper->lessThanOrEqual($from)) {
                continue;
            }

            $spanEnd = $qty->lessThan($upper) ? $qty : $upper;
            $band = $spanEnd->minus($from);
            if ($band->lessThanOrEqual('0')) {
                continue;
            }
            $amount = $amount->plus($band->times($slabRate));
            $cursor = $spanEnd;
            $remaining = $qty->minus($cursor);
            if ($remaining->lessThanOrEqual('0')) {
                break;
            }
        }

        return [$qty, $amount];
    }

    private function signedAmount(ElectricityTariffComponent $component, Decimal $amount): Decimal
    {
        if ($component->is_rebate || ($component->is_manual_adjustment && ($component->formula_config['sign'] ?? null) === 'credit')) {
            return $amount->negate();
        }

        return $amount;
    }
}
