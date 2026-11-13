<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\UtilitySite;
use App\Services\Utilities\ElectricityDashboardService;
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
        $filters = $request->validate(['month' => ['nullable', 'date']]);
        $month = isset($filters['month']) ? \Carbon\Carbon::parse($filters['month']) : now();

        $summary = $this->dashboard->summary($month, $withCosts);

        return Inertia::render('Utilities/Electricity/Dashboard', [
            'cards' => ElectricityPresenter::dashboardCards($summary['cards'], $withCosts),
            'alerts' => ElectricityPresenter::dashboardAlerts($summary['alerts'], $withCosts),
            'can_view_costs' => $withCosts,
            'filters' => ['month' => $month->startOfMonth()->toDateString()],
        ]);
    }
}
