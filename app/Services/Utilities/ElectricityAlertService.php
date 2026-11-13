<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Models\Utilities\ElectricityBill;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;

final class ElectricityAlertService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forMonth(string $monthStart, bool $includeCosts = true, ?int $utilitySiteId = null): array
    {
        $alerts = [];
        $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();
        $settings = $this->settings();

        $bills = ElectricityBill::query()
            ->with(['site:id,name,code', 'connection:id,connection_number,contract_demand,sanctioned_demand,low_pf_threshold', 'components'])
            ->whereBetween('billing_month', [$monthStart, $monthEnd])
            ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->get();

        foreach ($bills as $bill) {
            if (Decimal::of($bill->excess_demand ?? '0')->greaterThan('0')) {
                $allowed = $bill->connection?->contract_demand ?? $bill->connection?->sanctioned_demand;
                $alerts[] = [
                    'type' => 'demand_exceeded',
                    'severity' => 'high',
                    'site' => $bill->site?->name,
                    'month' => $bill->billing_month?->toDateString(),
                    'billing_demand' => $bill->reading_snapshot['billing_demand'] ?? null,
                    'allowed_demand' => $allowed !== null ? (string) $allowed : null,
                    'excess' => (string) $bill->excess_demand,
                    'penalty' => $includeCosts ? $this->penaltyTotal($bill) : null,
                    'bill_id' => $bill->id,
                ];
            }

            $threshold = $bill->connection?->low_pf_threshold;
            $pf = $bill->reading_snapshot['power_factor'] ?? null;
            if ($pf !== null && $threshold !== null && Decimal::of($pf)->lessThan($threshold)) {
                $alerts[] = [
                    'type' => 'low_pf',
                    'severity' => 'medium',
                    'site' => $bill->site?->name,
                    'month' => $bill->billing_month?->toDateString(),
                    'power_factor' => $pf,
                    'threshold' => (string) $threshold,
                    'bill_id' => $bill->id,
                ];
            }

            $penalty = $this->penaltyTotal($bill);
            if (Decimal::of($penalty)->greaterThan('0')) {
                $alerts[] = [
                    'type' => 'penalty',
                    'severity' => 'high',
                    'site' => $bill->site?->name,
                    'month' => $bill->billing_month?->toDateString(),
                    'amount' => $includeCosts ? $penalty : null,
                    'bill_id' => $bill->id,
                ];
            }

            if ($bill->due_date && $bill->status !== ElectricityBillStatus::Approved) {
                $days = now()->startOfDay()->diffInDays($bill->due_date, false);
                if ($days <= 7) {
                    $alerts[] = [
                        'type' => 'bill_due',
                        'severity' => $days < 0 ? 'high' : 'medium',
                        'site' => $bill->site?->name,
                        'due_date' => $bill->due_date->toDateString(),
                        'overdue' => $days < 0,
                        'bill_id' => $bill->id,
                    ];
                }
            }
        }

        $costSpikePct = $settings['cost_spike_percent'] ?? null;
        $consumptionSpikePct = $settings['consumption_spike_percent'] ?? null;
        if ($costSpikePct !== null || $consumptionSpikePct !== null) {
            $prevStart = Carbon::parse($monthStart)->subMonth()->startOfMonth()->toDateString();
            $prevEnd = Carbon::parse($monthStart)->subMonth()->endOfMonth()->toDateString();
            $prevBills = ElectricityBill::query()
                ->whereBetween('billing_month', [$prevStart, $prevEnd])
                ->whereNotIn('status', [ElectricityBillStatus::Cancelled->value])
                ->get()
                ->keyBy('electricity_connection_id');

            foreach ($bills as $bill) {
                $prev = $prevBills->get($bill->electricity_connection_id);
                if (! $prev) {
                    continue;
                }
                if ($includeCosts && $costSpikePct !== null) {
                    $curr = Decimal::of($bill->actual_bill_total ?? $bill->calculated_total);
                    $was = Decimal::of($prev->actual_bill_total ?? $prev->calculated_total);
                    if (! $was->isZero()) {
                        $pct = $curr->minus($was)->times(100)->dividedBy($was, 4);
                        if ($pct->greaterThan($costSpikePct)) {
                            $alerts[] = [
                                'type' => 'cost_spike',
                                'severity' => 'medium',
                                'site' => $bill->site?->name,
                                'percent' => $pct->toRate(),
                                'threshold' => (string) $costSpikePct,
                                'bill_id' => $bill->id,
                            ];
                        }
                    }
                }
                if ($consumptionSpikePct !== null) {
                    $currKwh = Decimal::of($bill->reading_snapshot['kwh_consumption'] ?? '0');
                    $wasKwh = Decimal::of($prev->reading_snapshot['kwh_consumption'] ?? '0');
                    if (! $wasKwh->isZero()) {
                        $pct = $currKwh->minus($wasKwh)->times(100)->dividedBy($wasKwh, 4);
                        if ($pct->greaterThan($consumptionSpikePct)) {
                            $alerts[] = [
                                'type' => 'consumption_spike',
                                'severity' => 'medium',
                                'site' => $bill->site?->name,
                                'percent' => $pct->toRate(),
                                'threshold' => (string) $consumptionSpikePct,
                                'bill_id' => $bill->id,
                            ];
                        }
                    }
                }
            }
        }

        return $alerts;
    }

    private function penaltyTotal(ElectricityBill $bill): string
    {
        return Decimal::sum(
            $bill->components->where('is_penalty', true)->map(fn ($c) => $c->actual_amount ?? $c->calculated_amount)
        )->toMoney();
    }

    /**
     * @return array{cost_spike_percent: ?string, consumption_spike_percent: ?string}
     */
    private function settings(): array
    {
        $company = app(CurrentCompany::class)->get();
        if (! $company) {
            return ['cost_spike_percent' => null, 'consumption_spike_percent' => null];
        }

        $elec = $company->setting('electricity', []);
        $elec = is_array($elec) ? $elec : [];

        return [
            'cost_spike_percent' => isset($elec['cost_spike_percent']) ? (string) $elec['cost_spike_percent'] : null,
            'consumption_spike_percent' => isset($elec['consumption_spike_percent']) ? (string) $elec['consumption_spike_percent'] : null,
        ];
    }
}
