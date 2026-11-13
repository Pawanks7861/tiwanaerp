<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\UtilitySite;
use App\Rules\ExistsInCompany;
use App\Services\Utilities\ElectricityDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityDashboardController extends Controller
{
    public function __construct(private readonly ElectricityDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $filters = $request->validate([
            'month' => ['nullable', 'date'],
            'utility_site_id' => ['nullable', 'integer', new ExistsInCompany(UtilitySite::class)],
        ]);

        $siteId = isset($filters['utility_site_id']) ? (int) $filters['utility_site_id'] : null;
        $month = isset($filters['month'])
            ? Carbon::parse($filters['month'])->startOfMonth()
            : $this->defaultMonth($siteId);

        $summary = $this->dashboard->summary($month, $withCosts, $siteId);

        return Inertia::render('Utilities/Electricity/Dashboard', [
            'cards' => ElectricityPresenter::dashboardCards($summary['cards'], $withCosts),
            'alerts' => ElectricityPresenter::dashboardAlerts($summary['alerts'], $withCosts),
            'can_view_costs' => $withCosts,
            'filters' => [
                'month' => $month->toDateString(),
                'utility_site_id' => $siteId !== null ? (string) $siteId : 'all',
            ],
            'available_months' => $this->availableMonths($siteId),
            'sites' => ElectricityPresenter::siteFilterOptions(),
        ]);
    }

    /**
     * Prefer the current calendar month; if empty, fall back to the latest billed month.
     */
    private function defaultMonth(?int $utilitySiteId = null): Carbon
    {
        $current = now()->startOfMonth();
        $hasCurrent = ElectricityBill::query()
            ->whereDate('billing_month', $current->toDateString())
            ->where('status', '!=', ElectricityBillStatus::Cancelled)
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->exists();

        if ($hasCurrent) {
            return $current;
        }

        $latest = ElectricityBill::query()
            ->where('status', '!=', ElectricityBillStatus::Cancelled)
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->max('billing_month');

        return $latest
            ? Carbon::parse($latest)->startOfMonth()
            : $current;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function availableMonths(?int $utilitySiteId = null): array
    {
        $months = ElectricityBill::query()
            ->where('status', '!=', ElectricityBillStatus::Cancelled)
            ->when($utilitySiteId, fn ($q) => $q->where('utility_site_id', $utilitySiteId))
            ->select('billing_month')
            ->distinct()
            ->orderByDesc('billing_month')
            ->pluck('billing_month')
            ->map(function ($date) {
                $carbon = Carbon::parse($date)->startOfMonth();

                return [
                    'value' => $carbon->toDateString(),
                    'label' => $carbon->format('M Y'),
                ];
            })
            ->values()
            ->all();

        $current = now()->startOfMonth();
        $currentValue = $current->toDateString();
        if (! collect($months)->contains(fn (array $m) => $m['value'] === $currentValue)) {
            array_unshift($months, [
                'value' => $currentValue,
                'label' => $current->format('M Y'),
            ]);
        }

        return $months;
    }
}
