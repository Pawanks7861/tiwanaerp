<?php

namespace App\Http\Controllers\Api\V1\Utilities;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilities\Electricity\ElectricityPresenter;
use App\Models\Utilities\UtilitySite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UtilitySiteApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = UtilitySite::query()
            ->withCount('connections')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $page->through(fn (UtilitySite $s) => ElectricityPresenter::site($s, true)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(UtilitySite $utilitySite): JsonResponse
    {
        Gate::authorize('view', $utilitySite);
        $utilitySite->loadCount('connections');

        return response()->json(['data' => ElectricityPresenter::site($utilitySite, true)]);
    }
}
