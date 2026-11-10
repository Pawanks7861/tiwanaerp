<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourResource;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Masters\MasterService;
use App\Support\Masters\MasterRegistry;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LabourRegisterController extends Controller
{
    public function __construct(private readonly MasterService $masters) {}

    public function crew(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [LabourAttendance::class, $project]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'trade_id' => ['nullable', 'integer'],
            'subcontractor_id' => ['nullable', 'integer'],
            'active' => ['nullable', Rule::in(['1', '0', 'true', 'false', 'yes', 'no'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $active = $filters['active'] ?? null;

        $query = Labour::query()
            ->where('current_project_id', $project->id)
            ->with(['trade:id,name', 'subcontractor:id,code,name', 'currentProject:id,code,name'])
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when(isset($filters['trade_id']), fn ($q) => $q->where('labour_trade_id', (int) $filters['trade_id']))
            ->when(isset($filters['subcontractor_id']), fn ($q) => $q->where('subcontractor_id', (int) $filters['subcontractor_id']))
            ->when($active !== null, fn ($q) => $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('is_active')
            ->orderBy('name');

        return LabourResource::collection(
            $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
        );
    }

    public function show(Request $request, Labour $labour): LabourResource
    {
        Gate::authorize('view', $labour);

        return new LabourResource($labour->load(['trade:id,name', 'subcontractor:id,code,name', 'currentProject:id,code,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $definition = MasterRegistry::get('labour');
        Gate::authorize('create', $definition->model());

        $data = $request->validate($definition->rules(null));
        $record = $this->masters->create($definition, $data);

        return (new LabourResource($record->load(['trade:id,name', 'subcontractor:id,code,name', 'currentProject:id,code,name'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Labour $labour): LabourResource
    {
        Gate::authorize('update', $labour);
        $definition = MasterRegistry::get('labour');
        $data = $request->validate($definition->rules($labour));
        $record = $this->masters->update($definition, $labour, $data);

        return new LabourResource($record->load(['trade:id,name', 'subcontractor:id,code,name', 'currentProject:id,code,name']));
    }
}
