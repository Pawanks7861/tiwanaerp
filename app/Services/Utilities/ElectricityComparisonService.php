<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityBillComponent;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

final class ElectricityComparisonService
{
    /**
     * Monthly comparison rows (workbook "Monthly Comparison" concept).
     *
     * @return list<array<string, mixed>>
     */
    public function monthly(?string $from = null, ?string $to = null, ?int $siteId = null, bool $includeCosts = true): array
    {
        $q = ElectricityBill::query()
            ->with(['site:id,code,name,state_code', 'connection:id,connection_number,contract_demand,sanctioned_demand,state_code'])
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($from, fn ($q) => $q->whereDate('billing_month', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('billing_month', '<=', $to))
            ->when($siteId, fn ($q) => $q->where('utility_site_id', $siteId))
            ->orderBy('billing_month')
            ->orderBy('utility_site_id');

        return $q->get()->map(function (ElectricityBill $bill) use ($includeCosts) {
            $row = [
                'bill_id' => $bill->id,
                'site' => $bill->site?->name,
                'site_code' => $bill->site?->code,
                'state' => $bill->site?->state_code ?? $bill->connection?->state_code,
                'month' => $bill->billing_month?->toDateString(),
                'kwh' => $bill->reading_snapshot['kwh_consumption'] ?? null,
                'kvah' => $bill->reading_snapshot['kvah_consumption'] ?? null,
                'billing_demand' => $bill->reading_snapshot['billing_demand'] ?? null,
                'contract_demand' => $bill->connection?->contract_demand !== null ? (string) $bill->connection->contract_demand : null,
                'demand_utilization_percent' => $bill->demand_utilization_percent !== null ? (string) $bill->demand_utilization_percent : null,
                'power_factor' => $bill->reading_snapshot['power_factor'] ?? null,
            ];

            if ($includeCosts) {
                $row['actual_bill'] = $bill->actual_bill_total !== null ? (string) $bill->actual_bill_total : null;
                $row['calculated_bill'] = (string) $bill->calculated_total;
                $row['variance'] = $bill->variance_total !== null ? (string) $bill->variance_total : null;
                $row['cost_per_kwh'] = $bill->cost_per_kwh !== null ? (string) $bill->cost_per_kwh : null;
                $row['cost_per_kvah'] = $bill->cost_per_kvah !== null ? (string) $bill->cost_per_kvah : null;
            }

            return $row;
        })->all();
    }

    /**
     * Component breakdown across sites/months — only components that exist.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function componentBreakdown(?string $from = null, ?string $to = null, bool $includeCosts = true): array
    {
        if (! $includeCosts) {
            return ['columns' => [], 'rows' => []];
        }

        $bills = ElectricityBill::query()
            ->with(['site:id,name', 'components'])
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($from, fn ($q) => $q->whereDate('billing_month', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('billing_month', '<=', $to))
            ->orderBy('billing_month')
            ->get();

        $codes = $bills->flatMap(fn ($b) => $b->components->pluck('component_code'))->unique()->values()->all();

        $rows = $bills->map(function (ElectricityBill $bill) use ($codes) {
            $byCode = $bill->components->keyBy('component_code');
            $row = [
                'site' => $bill->site?->name,
                'month' => $bill->billing_month?->toDateString(),
            ];
            foreach ($codes as $code) {
                $c = $byCode->get($code);
                $row[$code] = $c ? (string) ($c->actual_amount ?? $c->calculated_amount) : null;
            }

            return $row;
        })->all();

        return ['columns' => $codes, 'rows' => $rows];
    }

    /**
     * Side-by-side tariff comparison for a date.
     *
     * @return list<array<string, mixed>>
     */
    public function tariffsOn(string $onDate, bool $includeCosts = true): array
    {
        $resolver = app(ElectricityTariffResolver::class);
        $connections = \App\Models\Utilities\ElectricityConnection::query()
            ->with('site:id,name,code')
            ->where('is_active', true)
            ->get();

        $rows = [];
        foreach ($connections as $connection) {
            try {
                $tariff = $resolver->forPeriod($connection, \Carbon\Carbon::parse($onDate));
            } catch (\Throwable) {
                continue;
            }
            $tariff->load('components');
            $energy = $tariff->components->firstWhere('component_type', \App\Enums\Utilities\ElectricityComponentType::Energy);
            $demand = $tariff->components->firstWhere('component_type', \App\Enums\Utilities\ElectricityComponentType::Demand);
            $fuel = $tariff->components->first(fn ($c) => in_array($c->component_type, [
                \App\Enums\Utilities\ElectricityComponentType::Fuel,
                \App\Enums\Utilities\ElectricityComponentType::Fppca,
            ], true));
            $wheeling = $tariff->components->firstWhere('component_type', \App\Enums\Utilities\ElectricityComponentType::Wheeling);
            $duty = $tariff->components->firstWhere('component_type', \App\Enums\Utilities\ElectricityComponentType::Duty);

            $row = [
                'site' => $connection->site?->name,
                'connection' => $connection->connection_number,
                'tariff' => $tariff->name,
                'contract_demand' => $connection->contract_demand !== null ? (string) $connection->contract_demand : null,
                'energy_rate' => $includeCosts && $energy ? (string) $energy->rate : null,
                'demand_rate' => $includeCosts && $demand ? (string) $demand->rate : null,
                'fac_fppca' => $includeCosts && $fuel ? (string) ($fuel->rate ?: $fuel->percentage) : null,
                'wheeling' => $includeCosts && $wheeling ? (string) ($wheeling->rate ?: $wheeling->percentage) : null,
                'duty' => $includeCosts && $duty ? (string) ($duty->rate ?: $duty->percentage) : null,
                'tod' => $tariff->components->contains(fn ($c) => $c->calculation_basis === \App\Enums\Utilities\ElectricityCalculationBasis::TodSlotUnits),
                'pf_rule' => $tariff->components->contains(fn ($c) => $c->calculation_basis === \App\Enums\Utilities\ElectricityCalculationBasis::PowerFactorRule),
                'penalty_rule' => $tariff->components->contains(fn ($c) => $c->is_penalty),
                'subsidy' => $tariff->components->contains(fn ($c) => $c->component_type === \App\Enums\Utilities\ElectricityComponentType::Subsidy),
            ];
            $rows[] = $row;
        }

        return $rows;
    }
}
