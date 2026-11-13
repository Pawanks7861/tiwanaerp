<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityMeterApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ElectricityMeter::class);

        $filters = $request->validate([
            'electricity_connection_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ElectricityMeter::query()
            ->when($filters['electricity_connection_id'] ?? null, fn ($q, $id) => $q->where('electricity_connection_id', $id))
            ->orderBy('meter_number')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->through(fn (ElectricityMeter $m) => ElectricityPresenter::meter($m)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(ElectricityMeter $electricityMeter): JsonResponse
    {
        Gate::authorize('view', $electricityMeter);

        return response()->json(['data' => ElectricityPresenter::meter($electricityMeter)]);
    }
}
