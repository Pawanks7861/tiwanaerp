<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityBillComponent;
use App\Models\Utilities\ElectricityMeterReading;
use App\Support\Math\Decimal;
use Carbon\CarbonInterface;

final class ElectricityDashboardService
{
    /**
     * @return array{cards: list<array{key: string, label: string, value: mixed, financial?: bool}>, alerts: list<array<string, mixed>>}
     */
    public function summary(?CarbonInterface $month = null, bool $includeCosts = true, ?int $utilitySiteId = null): array
    {
        $monthStart = ($month ?? now())->copy()->startOfMonth()->toDateString();
        $monthEnd = ($month ?? now())->copy()->endOfMonth()->toDateString();

        $bills = ElectricityBill::query()
            ->with(['site:id,code,name', 'connection:id,connection_number,contract_demand,sanctioned_demand,low_pf_threshold'])
            ->whereBetween('billing_month', [$monthStart, $monthEnd])
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->get();

        $readings = ElectricityMeterReading::query()
            ->with('connection:id,low_pf_threshold')
            ->whereBetween('billing_month', [$monthStart, $monthEnd])
            ->where('status', '!=', 'cancelled')
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->get();

        $totalKwh = Decimal::sum($readings->pluck('kwh_consumption'));
        $totalKvah = Decimal::sum($readings->pluck('kvah_consumption')->filter(fn ($v) => $v !== null));
        $actualTotal = Decimal::sum($bills->pluck('actual_bill_total')->filter(fn ($v) => $v !== null));
        if ($actualTotal->isZero()) {
            $actualTotal = Decimal::sum($bills->pluck('calculated_total'));
        }

        $avgKwh = $totalKwh->isZero() ? null : $actualTotal->dividedBy($totalKwh, 4)->toRate();
        $avgKvah = $totalKvah->isZero() ? null : $actualTotal->dividedBy($totalKvah, 4)->toRate();

        $bySite = $bills->groupBy('utility_site_id')->map(function ($group) {
            $cost = Decimal::sum($group->map(fn ($b) => $b->actual_bill_total ?? $b->calculated_total));

            return [
                'site' => $group->first()->site?->name,
                'cost' => $cost->toMoney(),
                'utilization' => $group->max('demand_utilization_percent'),
            ];
        })->values();

        $highest = $bySite->sortByDesc(fn ($r) => (float) $r['cost'])->first();
        $lowest = $bySite->sortBy(fn ($r) => (float) $r['cost'])->first();
        $highestUtil = $bills->sortByDesc(fn ($b) => (float) ($b->demand_utilization_percent ?? 0))->first();

        $poorPf = $readings->filter(function ($r) {
            if ($r->power_factor === null) {
                return false;
            }
            $threshold = $r->connection?->low_pf_threshold ?? '0.9';

            return Decimal::of($r->power_factor)->lessThan($threshold);
        });

        $exceeded = $bills->filter(fn ($b) => Decimal::of($b->excess_demand ?? '0')->greaterThan('0'));

        $penalty = Decimal::of(
            (string) (ElectricityBillComponent::query()
                ->whereIn('electricity_bill_id', $bills->pluck('id'))
                ->where('is_penalty', true)
                ->selectRaw('COALESCE(SUM(COALESCE(actual_amount, calculated_amount)), 0) as t')
                ->value('t') ?? '0')
        );
        $rebate = Decimal::of(
            (string) (ElectricityBillComponent::query()
                ->whereIn('electricity_bill_id', $bills->pluck('id'))
                ->where('is_rebate', true)
                ->selectRaw('COALESCE(SUM(COALESCE(actual_amount, calculated_amount)), 0) as t')
                ->value('t') ?? '0')
        );

        $cards = [
            ['key' => 'total_cost', 'label' => 'Current month electricity cost', 'value' => $actualTotal->toMoney(), 'financial' => true],
            ['key' => 'total_kwh', 'label' => 'Total kWh', 'value' => $totalKwh->toQuantity(), 'financial' => false],
            ['key' => 'total_kvah', 'label' => 'Total kVAh', 'value' => $totalKvah->toQuantity(), 'financial' => false],
            ['key' => 'avg_kwh', 'label' => 'Average ₹/kWh', 'value' => $avgKwh, 'financial' => true],
            ['key' => 'avg_kvah', 'label' => 'Average ₹/kVAh', 'value' => $avgKvah, 'financial' => true],
            ['key' => 'highest_site', 'label' => 'Highest cost site', 'value' => $highest['site'] ?? '—', 'financial' => false],
            ['key' => 'lowest_site', 'label' => 'Lowest cost site', 'value' => $lowest['site'] ?? '—', 'financial' => false],
            ['key' => 'highest_util', 'label' => 'Highest demand utilization', 'value' => $highestUtil?->demand_utilization_percent, 'financial' => false],
            ['key' => 'poor_pf', 'label' => 'Poor PF sites', 'value' => $poorPf->count(), 'financial' => false],
            ['key' => 'demand_exceeded', 'label' => 'Demand exceeded sites', 'value' => $exceeded->count(), 'financial' => false],
            ['key' => 'penalty', 'label' => 'Total penalty', 'value' => $penalty->toMoney(), 'financial' => true],
            ['key' => 'rebate', 'label' => 'Total rebate', 'value' => $rebate->toMoney(), 'financial' => true],
        ];

        if (! $includeCosts) {
            $cards = array_map(function (array $c) {
                if ($c['financial'] ?? false) {
                    $c['value'] = null;
                    $c['redacted'] = true;
                }

                return $c;
            }, $cards);
        }

        return [
            'cards' => $cards,
            'alerts' => app(ElectricityAlertService::class)->forMonth($monthStart, $includeCosts, $utilitySiteId),
        ];
    }
}
