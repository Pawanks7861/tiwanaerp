<?php

namespace App\Http\Controllers\Api\V1\Scrap;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScrapBalanceResource;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapBalance;
use App\Models\Scrap\ScrapGeneration;
use App\Services\Scrap\ScrapBalanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ScrapBalanceApiController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $filters = $request->validate([
            'scrap_type_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = ScrapBalance::query()
            ->where('project_id', $project->id)
            ->with(['scrapType.unit', 'unit'])
            ->when($filters['scrap_type_id'] ?? null, fn ($q, $id) => $q->where('scrap_type_id', $id))
            ->where('quantity', '>', 0)
            ->orderBy('scrap_type_id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return ScrapBalanceResource::collection($rows);
    }

    public function summary(Project $project, ScrapBalanceService $balances): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        return response()->json(['data' => $balances->summary($project->id)->values()]);
    }

    public function available(Request $request, Project $project, ScrapBalanceService $balances): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $data = $request->validate([
            'scrap_type_id' => ['required', 'integer'],
            'unit_id' => ['required', 'integer'],
        ]);

        $qty = $balances->availableQty($project->id, (int) $data['scrap_type_id'], (int) $data['unit_id']);

        return response()->json(['data' => ['quantity' => $qty->toQuantity()]]);
    }
}
