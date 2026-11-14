<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\Utilities\ElectricityCalculationBasis;
use App\Enums\Utilities\ElectricityComponentType;
use App\Models\User;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityBillComponent;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\ElectricityTariff;
use App\Models\Utilities\ElectricityTariffComponent;
use App\Models\Utilities\UtilitySite;
use Carbon\Carbon;

final class ElectricityPresenter
{
    public static function seesCosts(User $user): bool
    {
        return $user->can('utilities.electricity.view_costs');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dashboardCards(array $cards, bool $withCosts): array
    {
        $map = [
            'total_cost' => 'money',
            'avg_kwh' => 'rate',
            'avg_kvah' => 'rate',
            'penalty' => 'money',
            'rebate' => 'money',
            'total_kwh' => 'quantity',
            'total_kvah' => 'quantity',
        ];

        return array_map(function (array $card) use ($map, $withCosts) {
            $key = $card['key'];
            $card['kind'] = $map[$key] ?? 'text';
            if (($card['financial'] ?? false) && ! $withCosts) {
                $card['value'] = null;
            }
            if ($card['kind'] === 'rate') {
                $card['unit'] = str_contains($key, 'kvah') ? 'kVAh' : 'kWh';
            }
            if (in_array($card['kind'], ['quantity'], true)) {
                $card['unit'] = str_contains($key, 'kvah') ? 'kVAh' : 'kWh';
            }

            return $card;
        }, $cards);
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    public static function dashboardAlerts(array $alerts, bool $withCosts): array
    {
        return array_map(function (array $a) use ($withCosts) {
            if (! $withCosts) {
                unset($a['amount'], $a['penalty']);
            }

            return [
                ...self::alertCopy($a),
                ...$a,
            ];
        }, $alerts);
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array{title: string, message: ?string}
     */
    private static function alertCopy(array $alert): array
    {
        $site = $alert['site'] ?? 'Site';

        return match ($alert['type'] ?? '') {
            'demand_exceeded' => [
                'title' => 'Demand exceeded',
                'message' => "{$site}: billing demand above contract (excess {$alert['excess']}).",
            ],
            'low_pf' => [
                'title' => 'Low power factor',
                'message' => "{$site}: PF {$alert['power_factor']} below threshold {$alert['threshold']}.",
            ],
            'penalty' => [
                'title' => 'Penalty on bill',
                'message' => "{$site}: penalty components detected on the bill.",
            ],
            'bill_due' => [
                'title' => ($alert['overdue'] ?? false) ? 'Bill overdue' : 'Bill due soon',
                'message' => "{$site}: due {$alert['due_date']}.",
            ],
            'cost_spike' => [
                'title' => 'Cost spike',
                'message' => "{$site}: cost up {$alert['percent']}% vs previous month (threshold {$alert['threshold']}%).",
            ],
            'consumption_spike' => [
                'title' => 'Consumption spike',
                'message' => "{$site}: kWh up {$alert['percent']}% vs previous month.",
            ],
            default => ['title' => ucfirst(str_replace('_', ' ', (string) ($alert['type'] ?? 'Alert'))), 'message' => $site],
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function siteFilterOptions(): array
    {
        return UtilitySite::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (UtilitySite $s) => ['value' => $s->id, 'label' => "{$s->code} — {$s->name}"])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function site(UtilitySite $site, bool $withCounts = false): array
    {
        $data = [
            'id' => $site->id,
            'code' => $site->code,
            'name' => $site->name,
            'state_code' => $site->state_code,
            'city' => $site->city,
            'address' => $site->address,
            'is_active' => $site->is_active,
        ];
        if ($withCounts) {
            $data['connections_count'] = $site->connections_count ?? $site->connections()->count();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(ElectricityConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'utility_site_id' => $connection->utility_site_id,
            'vendor_id' => $connection->vendor_id,
            'connection_number' => $connection->connection_number,
            'consumer_number' => $connection->consumer_number,
            'discom_name' => $connection->discom_name,
            'state_code' => $connection->state_code,
            'tariff_category' => $connection->tariff_category,
            'voltage_level' => $connection->voltage_level,
            'sanctioned_demand' => $connection->sanctioned_demand !== null ? (string) $connection->sanctioned_demand : null,
            'contract_demand' => $connection->contract_demand !== null ? (string) $connection->contract_demand : null,
            'demand_unit' => $connection->demand_unit,
            'metering_type' => $connection->metering_type,
            'billing_cycle' => $connection->billing_cycle,
            'default_multiplication_factor' => $connection->default_multiplication_factor !== null ? (string) $connection->default_multiplication_factor : null,
            'low_pf_threshold' => $connection->low_pf_threshold !== null ? (string) $connection->low_pf_threshold : null,
            'tod_enabled' => $connection->tod_enabled,
            'kwh_enabled' => $connection->kwh_enabled,
            'kvah_enabled' => $connection->kvah_enabled,
            'is_active' => $connection->is_active,
            'notes' => $connection->notes,
            'site' => $connection->relationLoaded('site') && $connection->site
                ? self::site($connection->site)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function meter(ElectricityMeter $meter): array
    {
        return [
            'id' => $meter->id,
            'electricity_connection_id' => $meter->electricity_connection_id,
            'meter_number' => $meter->meter_number,
            'meter_type' => $meter->meter_type,
            'make' => $meter->make,
            'model' => $meter->model,
            'multiplication_factor' => (string) $meter->multiplication_factor,
            'installed_at' => $meter->installed_at?->toDateString(),
            'removed_at' => $meter->removed_at?->toDateString(),
            'is_active' => $meter->is_active,
            'notes' => $meter->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tariff(ElectricityTariff $tariff, bool $withCosts = true): array
    {
        return [
            'id' => $tariff->id,
            'electricity_connection_id' => $tariff->electricity_connection_id,
            'name' => $tariff->name,
            'effective_from' => $tariff->effective_from?->toDateString(),
            'effective_to' => $tariff->effective_to?->toDateString(),
            'tariff_category' => $tariff->tariff_category,
            'state_code' => $tariff->state_code,
            'description' => $tariff->description,
            'is_active' => $tariff->is_active,
            'connection' => $tariff->relationLoaded('connection') && $tariff->connection
                ? self::connection($tariff->connection)
                : null,
            'components' => $tariff->relationLoaded('components')
                ? $tariff->components->map(fn (ElectricityTariffComponent $c) => self::tariffComponent($c, $withCosts))->all()
                : [],
            'components_count' => $tariff->components_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tariffComponent(ElectricityTariffComponent $c, bool $withCosts = true): array
    {
        $row = [
            'id' => $c->id,
            'component_code' => $c->component_code,
            'component_name' => $c->component_name,
            'component_type' => $c->component_type->value,
            'component_type_label' => $c->component_type->label(),
            'calculation_basis' => $c->calculation_basis->value,
            'calculation_basis_label' => $c->calculation_basis->label(),
            'unit' => $c->unit,
            'sort_order' => $c->sort_order,
            'is_rebate' => $c->is_rebate,
            'is_tax' => $c->is_tax,
            'is_penalty' => $c->is_penalty,
            'is_active' => $c->is_active,
        ];
        if ($withCosts) {
            $row['rate'] = (string) $c->rate;
            $row['percentage'] = (string) $c->percentage;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function reading(ElectricityMeterReading $reading): array
    {
        return [
            'id' => $reading->id,
            'utility_site_id' => $reading->utility_site_id,
            'electricity_connection_id' => $reading->electricity_connection_id,
            'electricity_meter_id' => $reading->electricity_meter_id,
            'billing_month' => $reading->billing_month?->toDateString(),
            'from_date' => $reading->from_date?->toDateString(),
            'to_date' => $reading->to_date?->toDateString(),
            'status' => $reading->status->value,
            'status_label' => $reading->status->label(),
            'days' => $reading->days,
            'opening_kwh' => (string) $reading->opening_kwh,
            'closing_kwh' => (string) $reading->closing_kwh,
            'opening_kvah' => $reading->opening_kvah !== null ? (string) $reading->opening_kvah : null,
            'closing_kvah' => $reading->closing_kvah !== null ? (string) $reading->closing_kvah : null,
            'mdi_reading' => $reading->mdi_reading !== null ? (string) $reading->mdi_reading : null,
            'multiplication_factor' => (string) $reading->multiplication_factor,
            'kwh_consumption' => (string) $reading->kwh_consumption,
            'kvah_consumption' => $reading->kvah_consumption !== null ? (string) $reading->kvah_consumption : null,
            'billing_demand' => $reading->billing_demand !== null ? (string) $reading->billing_demand : null,
            'power_factor' => $reading->power_factor !== null ? (string) $reading->power_factor : null,
            'remarks' => $reading->remarks,
            'confirmed_at' => $reading->confirmed_at?->toIso8601String(),
            'site' => $reading->relationLoaded('site') && $reading->site ? self::site($reading->site) : null,
            'connection' => $reading->relationLoaded('connection') && $reading->connection
                ? ['id' => $reading->connection->id, 'connection_number' => $reading->connection->connection_number]
                : null,
            'meter' => $reading->relationLoaded('meter') && $reading->meter
                ? ['id' => $reading->meter->id, 'meter_number' => $reading->meter->meter_number]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function bill(ElectricityBill $bill, bool $withCosts = true): array
    {
        $row = [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'billing_month' => $bill->billing_month?->toDateString(),
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            'electricity_meter_reading_id' => $bill->electricity_meter_reading_id,
            'electricity_tariff_id' => $bill->electricity_tariff_id,
            'demand_utilization_percent' => $bill->demand_utilization_percent !== null ? (string) $bill->demand_utilization_percent : null,
            'excess_demand' => $bill->excess_demand !== null ? (string) $bill->excess_demand : null,
            'remarks' => $bill->remarks,
            'site' => $bill->relationLoaded('site') && $bill->site ? self::site($bill->site) : null,
            'connection' => $bill->relationLoaded('connection') && $bill->connection
                ? ['id' => $bill->connection->id, 'connection_number' => $bill->connection->connection_number]
                : null,
        ];
        if ($withCosts) {
            $row['calculated_total'] = (string) $bill->calculated_total;
            $row['actual_bill_total'] = $bill->actual_bill_total !== null ? (string) $bill->actual_bill_total : null;
            $row['variance_total'] = $bill->variance_total !== null ? (string) $bill->variance_total : null;
            $row['cost_per_kwh'] = $bill->cost_per_kwh !== null ? (string) $bill->cost_per_kwh : null;
            $row['cost_per_kvah'] = $bill->cost_per_kvah !== null ? (string) $bill->cost_per_kvah : null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function billComponent(ElectricityBillComponent $c, bool $withCosts = true): array
    {
        $row = [
            'id' => $c->id,
            'tariff_component_id' => $c->tariff_component_id,
            'component_code' => $c->component_code,
            'component_name' => $c->component_name,
            'calculation_basis' => $c->calculation_basis,
            'quantity' => $c->quantity !== null ? (string) $c->quantity : null,
            'is_rebate' => $c->is_rebate,
            'is_tax' => $c->is_tax,
            'is_penalty' => $c->is_penalty,
        ];
        if ($withCosts) {
            $row['rate'] = $c->rate !== null ? (string) $c->rate : null;
            $row['calculated_amount'] = (string) $c->calculated_amount;
            $row['actual_amount'] = $c->actual_amount !== null ? (string) $c->actual_amount : null;
            $row['variance_amount'] = $c->variance_amount !== null ? (string) $c->variance_amount : null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tariffDiff(?ElectricityTariff $left, ?ElectricityTariff $right, bool $withCosts = true): array
    {
        if (! $left || ! $right) {
            return [];
        }
        $leftBy = $left->components->keyBy('component_code');
        $rightBy = $right->components->keyBy('component_code');
        $codes = $leftBy->keys()->merge($rightBy->keys())->unique()->sort()->values();

        return $codes->map(function (string $code) use ($leftBy, $rightBy, $withCosts) {
            $l = $leftBy->get($code);
            $r = $rightBy->get($code);
            $lRate = $l ? (float) $l->rate : null;
            $rRate = $r ? (float) $r->rate : null;
            $delta = ($lRate !== null && $rRate !== null) ? $rRate - $lRate : null;
            $basis = $l?->calculation_basis ?? $r?->calculation_basis;

            return [
                'component_code' => $code,
                'component_name' => $l?->component_name ?? $r?->component_name,
                'left_rate' => $withCosts ? ($l ? (string) $l->rate : null) : null,
                'right_rate' => $withCosts ? ($r ? (string) $r->rate : null) : null,
                'rate_delta' => $withCosts ? $delta : null,
                'calculation_basis' => $basis instanceof ElectricityCalculationBasis ? $basis->value : $basis,
                'calculation_basis_label' => $basis instanceof ElectricityCalculationBasis ? $basis->label() : null,
                'missing_left' => $l === null,
                'missing_right' => $r === null,
            ];
        })->all();
    }

    /**
     * Pivot monthly comparison rows for the Inertia comparison page.
     *
     * @param  list<array<string, mixed>>  $flat
     * @return array{months: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public static function monthlyComparisonPivot(array $flat, bool $withCosts): array
    {
        $byMonth = collect($flat)->groupBy('month');
        $months = $byMonth->keys()->sort()->values()->map(fn (string $m) => [
            'key' => $m,
            'month' => $m,
            'label' => Carbon::parse($m)->format('M Y'),
        ])->all();

        $metricDefs = [
            ['key' => 'kwh', 'label' => 'Total kWh', 'kind' => 'quantity', 'unit' => 'kWh', 'field' => 'kwh', 'agg' => 'sum'],
            ['key' => 'kvah', 'label' => 'Total kVAh', 'kind' => 'quantity', 'unit' => 'kVAh', 'field' => 'kvah', 'agg' => 'sum'],
            ['key' => 'calculated_bill', 'label' => 'Calculated bill', 'kind' => 'money', 'field' => 'calculated_bill', 'agg' => 'sum', 'financial' => true],
            ['key' => 'actual_bill', 'label' => 'Actual bill', 'kind' => 'money', 'field' => 'actual_bill', 'agg' => 'sum', 'financial' => true],
        ];

        $rows = [];
        foreach ($metricDefs as $def) {
            if (($def['financial'] ?? false) && ! $withCosts) {
                continue;
            }
            $values = [];
            foreach ($months as $month) {
                $group = $byMonth->get($month['month'], collect());
                $values[] = $def['agg'] === 'sum'
                    ? $group->sum(fn ($r) => (float) ($r[$def['field']] ?? 0))
                    : $group->avg(fn ($r) => (float) ($r[$def['field']] ?? 0));
            }
            $rows[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'kind' => $def['kind'],
                'unit' => $def['unit'] ?? null,
                'values' => $values,
            ];
        }

        return ['months' => $months, 'rows' => $rows];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function connectionFilterOptions(): array
    {
        return ElectricityConnection::query()
            ->with('site:id,code,name')
            ->orderBy('connection_number')
            ->get(['id', 'utility_site_id', 'connection_number'])
            ->map(fn (ElectricityConnection $c) => [
                'value' => $c->id,
                'label' => $c->connection_number.($c->site ? " ({$c->site->name})" : ''),
            ])->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function readingOptionsForBill(): array
    {
        return ElectricityMeterReading::query()
            ->with([
                'site:id,code,name',
                'connection:id,connection_number',
                'meter:id,meter_number',
            ])
            ->where('status', 'confirmed')
            ->whereDoesntHave('bill')
            ->latest('billing_month')
            ->limit(200)
            ->get()
            ->map(fn (ElectricityMeterReading $r) => [
                'value' => $r->id,
                'label' => ($r->site?->name ?? 'Site').' — '.($r->billing_month?->format('M Y') ?? '').' — '.($r->meter?->meter_number ?? ''),
                'description' => $r->connection?->connection_number,
            ])->all();
    }

    public static function componentTypeOptions(): array
    {
        return ElectricityComponentType::options();
    }

    public static function calculationBasisOptions(): array
    {
        return ElectricityCalculationBasis::options();
    }
}
