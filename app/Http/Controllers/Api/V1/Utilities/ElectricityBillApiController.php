<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\ElectricityBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ElectricityBillApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ElectricityBill::class);

        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $filters = $request->validate([
            'utility_site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ElectricityBill::query()
            ->with('site:id,name')
            ->when($filters['utility_site_id'] ?? null, fn ($q, $id) => $q->where('utility_site_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('billing_month')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->through(fn (ElectricityBill $b) => ElectricityPresenter::bill($b, $withCosts)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(ElectricityBill $electricityBill, Request $request): JsonResponse
    {
        Gate::authorize('view', $electricityBill);
        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $electricityBill->load(['site', 'connection', 'components']);

        return response()->json([
            'data' => [
                ...ElectricityPresenter::bill($electricityBill, $withCosts),
                'components' => $electricityBill->components
                    ->map(fn ($c) => ElectricityPresenter::billComponent($c, $withCosts))->all(),
            ],
        ]);
    }
}
