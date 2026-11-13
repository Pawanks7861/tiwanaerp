<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\ElectricityConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityConnectionApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ElectricityConnection::class);

        $filters = $request->validate([
            'utility_site_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ElectricityConnection::query()
            ->with('site:id,code,name')
            ->when($filters['utility_site_id'] ?? null, fn ($q, $id) => $q->where('utility_site_id', $id))
            ->orderBy('connection_number')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->through(fn (ElectricityConnection $c) => ElectricityPresenter::connection($c)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(ElectricityConnection $electricityConnection): JsonResponse
    {
        Gate::authorize('view', $electricityConnection);
        $electricityConnection->load(['site', 'meters']);

        return response()->json([
            'data' => [
                ...ElectricityPresenter::connection($electricityConnection),
                'meters' => $electricityConnection->meters->map(fn ($m) => ElectricityPresenter::meter($m))->all(),
            ],
        ]);
    }
}
