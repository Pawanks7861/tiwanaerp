<?php

namespace App\Http\Controllers\Api\V1\Scrap;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScrapLedgerResource;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScrapLedgerApiController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(ScrapLedgerTxnType::class)],
            'scrap_type_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = ScrapLedgerEntry::query()
            ->where('project_id', $project->id)
            ->with(['scrapType:id,name,code', 'unit:id,symbol'])
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('transaction_type', $t))
            ->when($filters['scrap_type_id'] ?? null, fn ($q, $id) => $q->where('scrap_type_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return ScrapLedgerResource::collection($rows);
    }
}
