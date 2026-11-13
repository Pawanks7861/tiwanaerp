<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\UtilitySite;
use App\Services\Utilities\ElectricityComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityComparisonApiController extends Controller
{
    public function __construct(private readonly ElectricityComparisonService $comparison) {}

    public function monthly(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'utility_site_id' => ['nullable', 'integer'],
        ]);

        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $flat = $this->comparison->monthly(
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            isset($filters['utility_site_id']) ? (int) $filters['utility_site_id'] : null,
            $withCosts,
        );

        return response()->json(['data' => $flat, 'can_view_costs' => $withCosts]);
    }

    public function tariffs(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate(['on' => ['required', 'date']]);
        $withCosts = ElectricityPresenter::seesCosts($request->user());

        return response()->json([
            'data' => $this->comparison->tariffsOn($filters['on'], $withCosts),
            'can_view_costs' => $withCosts,
        ]);
    }
}
