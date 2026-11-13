<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\UtilitySite;
use App\Services\Utilities\ElectricityDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricitySummaryApiController extends Controller
{
    public function __construct(private readonly ElectricityDashboardService $dashboard) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $filters = $request->validate(['month' => ['nullable', 'date']]);
        $month = isset($filters['month']) ? \Carbon\Carbon::parse($filters['month']) : now();
        $summary = $this->dashboard->summary($month, $withCosts);

        return response()->json([
            'data' => [
                'cards' => ElectricityPresenter::dashboardCards($summary['cards'], $withCosts),
                'alerts' => ElectricityPresenter::dashboardAlerts($summary['alerts'], $withCosts),
                'can_view_costs' => $withCosts,
            ],
        ]);
    }
}
