<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\ElectricityTariff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityTariffApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ElectricityTariff::class);

        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $filters = $request->validate([
            'electricity_connection_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ElectricityTariff::query()
            ->with(['connection:id,connection_number', 'components'])
            ->when($filters['electricity_connection_id'] ?? null, fn ($q, $id) => $q->where('electricity_connection_id', $id))
            ->orderByDesc('effective_from')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->through(fn (ElectricityTariff $t) => ElectricityPresenter::tariff($t, $withCosts)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(ElectricityTariff $electricityTariff, Request $request): JsonResponse
    {
        Gate::authorize('view', $electricityTariff);
        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $electricityTariff->load(['connection', 'components']);

        return response()->json(['data' => ElectricityPresenter::tariff($electricityTariff, $withCosts)]);
    }
}
