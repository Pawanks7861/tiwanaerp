<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Http\Controllers\Controller;
use App\Models\Utilities\UtilitySite;
use App\Services\Utilities\ElectricityComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityComparisonController extends Controller
{
    public function __construct(private readonly ElectricityComparisonService $comparison) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'utility_site_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $flat = $this->comparison->monthly(
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            isset($filters['utility_site_id']) ? (int) $filters['utility_site_id'] : null,
            $withCosts,
        );
        $pivot = ElectricityPresenter::monthlyComparisonPivot($flat, $withCosts);

        return Inertia::render('Utilities/Electricity/Comparison/Index', [
            'months' => $pivot['months'],
            'rows' => $pivot['rows'],
            'totals' => null,
            'filters' => $filters,
            'sites' => ElectricityPresenter::siteFilterOptions(),
            'can_view_costs' => $withCosts,
        ]);
    }
}
