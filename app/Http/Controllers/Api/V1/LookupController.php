<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IndianState;
use App\Enums\Inventory\AdjustmentReason;
use App\Enums\Inventory\MaterialReturnType;
use App\Enums\Procurement\GrnExtraReason;
use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Equipment\Equipment;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\ExpenseCategory;
use App\Models\Labour\Labour;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\Quality\QualityChecklist;
use App\Services\Core\CompanyDirectory;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight mobile pickers. Same company scope and permissions as the web forms.
 */
class LookupController extends Controller
{
    public function __construct(
        private readonly CompanyDirectory $directory,
        private readonly PlanningService $planning,
    ) {}

    public function clients(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $clients = Client::query()
            ->active()
            ->search($search !== '' ? $search : null)
            ->orderBy('company_name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($clients->items())->map(fn (Client $client) => [
                'id' => $client->id,
                'code' => $client->code,
                'company_name' => $client->company_name,
                'contact_person' => $client->contact_person,
                'mobile' => $client->mobile,
            ])->all(),
            'meta' => $this->meta($clients),
            'links' => $this->links($clients),
        ]);
    }

    public function projectManagers(Request $request): JsonResponse
    {
        Gate::authorize('create', \App\Models\Projects\Project::class);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $users = $this->directory->membersQuery()
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($users->items())->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'role_label' => $user->getRoleNames()->sort()->values()->implode(', ') ?: null,
            ])->all(),
            'meta' => $this->meta($users),
            'links' => $this->links($users),
        ]);
    }

    public function states(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (array $option) => [
                    'code' => $option['value'],
                    'name' => $option['label'],
                ],
                IndianState::options(),
            ),
        ]);
    }

    public function materials(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.items.view') || $request->user()->can('material_requests.create') || $request->user()->can('purchase.create'), 403);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $materials = Material::query()
            ->active()
            ->with('unit:id,symbol,name')
            ->search($search !== '' ? $search : null)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($materials->items())->map(fn (Material $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'description' => $m->description,
                'item_type' => $m->item_type?->value ?? $m->item_type,
                'hsn_sac' => $m->hsn_sac,
                'tax_rate_id' => $m->tax_rate_id,
                'is_active' => (bool) $m->is_active,
                'unit' => $m->unit ? [
                    'id' => $m->unit->id,
                    'symbol' => $m->unit->symbol,
                    'name' => $m->unit->name,
                ] : null,
            ])->all(),
            'meta' => $this->meta($materials),
            'links' => $this->links($materials),
        ]);
    }

    public function vendors(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.vendors.view') || $request->user()->can('purchase.create') || $request->user()->can('purchase.view'), 403);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $vendors = Vendor::query()
            ->active()
            ->search($search !== '' ? $search : null)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($vendors->items())->map(fn (Vendor $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'name' => $v->name,
                'gstin' => $v->gstin,
                'state_code' => $v->state_code,
                'city' => $v->city,
                'payment_terms' => $v->payment_terms,
                'is_active' => (bool) $v->is_active,
            ])->all(),
            'meta' => $this->meta($vendors),
            'links' => $this->links($vendors),
        ]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.warehouses.view') || $request->user()->can('grn.create') || $request->user()->can('grn.view'), 403);

        $projectId = $request->integer('project_id') ?: null;
        if ($projectId) {
            $project = Project::query()->findOrFail($projectId);
            Gate::authorize('view', $project);
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $warehouses = Warehouse::query()
            ->active()
            ->with(['project:id,code,name'])
            ->when($projectId, fn ($q) => $q->where(fn ($w) => $w->whereNull('project_id')->orWhere('project_id', $projectId)))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($warehouses->items())->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'type' => $w->type?->value ?? $w->type,
                'is_active' => (bool) $w->is_active,
                'project' => $w->project ? $w->project->only(['id', 'code', 'name']) : null,
            ])->all(),
            'meta' => $this->meta($warehouses),
            'links' => $this->links($warehouses),
        ]);
    }

    public function taxRates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.tax_rates.view') || $request->user()->can('purchase.create') || $request->user()->can('purchase.view'), 403);

        $rates = TaxRate::query()->active()->orderBy('rate')->get(['id', 'name', 'rate', 'cgst_rate', 'sgst_rate', 'igst_rate']);

        return response()->json([
            'data' => $rates->map(fn (TaxRate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'rate' => $t->rate,
                'cgst_rate' => $t->cgst_rate,
                'sgst_rate' => $t->sgst_rate,
                'igst_rate' => $t->igst_rate,
            ])->all(),
        ]);
    }

    public function units(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.units.view') || $request->user()->can('material_requests.create'), 403);

        $units = Unit::query()->active()->orderBy('symbol')->get(['id', 'symbol', 'name']);

        return response()->json([
            'data' => $units->map(fn (Unit $u) => [
                'id' => $u->id,
                'symbol' => $u->symbol,
                'name' => $u->name,
            ])->all(),
        ]);
    }

    public function grnExtraReasons(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (array $option) => ['value' => $option['value'], 'label' => $option['label']],
                GrnExtraReason::options(),
            ),
        ]);
    }

    public function adjustmentReasons(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (array $option) => ['value' => $option['value'], 'label' => $option['label']],
                AdjustmentReason::options(),
            ),
        ]);
    }

    public function materialReturnTypes(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (array $option) => ['value' => $option['value'], 'label' => $option['label']],
                MaterialReturnType::options(),
            ),
        ]);
    }

    public function sites(Request $request): JsonResponse
    {
        $projectId = $request->integer('project_id');
        abort_unless($projectId > 0, 422, 'project_id is required.');
        $project = Project::query()->findOrFail($projectId);
        Gate::authorize('view', $project);

        $search = trim((string) $request->query('search', ''));
        $activeOnly = $request->boolean('active_only', true);

        $sites = Site::query()
            ->where('project_id', $project->id)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('address', 'like', $term));
            })
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'address', 'is_active']);

        return response()->json([
            'data' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'address' => $s->address,
                'is_active' => (bool) $s->is_active,
            ])->all(),
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $projectId = $request->integer('project_id');
        abort_unless($projectId > 0, 422, 'project_id is required.');
        $project = Project::query()->findOrFail($projectId);
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('planning.view') || $request->user()->can('site_diary.view') || $request->user()->can('dpr.view'), 403);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $tasks = ProjectTask::query()
            ->where('project_id', $project->id)
            ->with(['unit:id,symbol', 'boqItem:id,item_code,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('wbs_code', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($tasks->items())->map(fn (ProjectTask $t) => [
                'id' => $t->id,
                'wbs_code' => $t->wbs_code,
                'name' => $t->name,
                'status' => $t->status?->value,
                'unit' => $t->unit?->only(['id', 'symbol']),
                'boq_item' => $t->boqItem?->only(['id', 'item_code', 'name']),
            ])->all(),
            'meta' => $this->meta($tasks),
            'links' => $this->links($tasks),
        ]);
    }

    public function boqItems(Request $request): JsonResponse
    {
        $projectId = $request->integer('project_id');
        abort_unless($projectId > 0, 422, 'project_id is required.');
        $project = Project::query()->findOrFail($projectId);
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view') || $request->user()->can('site_diary.view') || $request->user()->can('dpr.view'), 403);

        $search = trim((string) $request->query('search', ''));
        $items = $this->planning->linkableBoqItems($project)
            ->with(['unit:id,symbol', 'boq:id,boq_number,version'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('item_code', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('boq_id')->orderBy('sort_order')->orderBy('id')
            ->limit(500)
            ->get(['id', 'boq_id', 'item_code', 'name', 'quantity', 'unit_id', 'line_uid']);

        return response()->json([
            'data' => $items->map(fn ($i) => [
                'id' => $i->id,
                'item_code' => $i->item_code,
                'name' => $i->name,
                'line_uid' => $i->line_uid,
                'quantity' => $i->quantity,
                'unit' => $i->unit?->only(['id', 'symbol']),
                'boq' => $i->boq?->only(['id', 'boq_number', 'version']),
            ])->all(),
        ]);
    }

    public function labourTrades(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.labour_trades.view') || $request->user()->can('site_diary.view') || $request->user()->can('site_diary.create'), 403);

        $search = trim((string) $request->query('search', ''));
        $trades = LabourTrade::query()
            ->active()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'is_active']);

        return response()->json([
            'data' => $trades->map(fn (LabourTrade $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_active' => (bool) $t->is_active,
            ])->all(),
        ]);
    }

    public function labours(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('labour.view') || $request->user()->can('labour.mark_attendance'), 403);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = $this->perPage($request, (int) ($filters['per_page'] ?? 25));

        $rows = Labour::query()
            ->active()
            ->with('trade:id,name')
            ->when(isset($filters['project_id']), fn ($q) => $q->where('current_project_id', (int) $filters['project_id']))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Labour $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'trade' => $l->trade?->name,
                'current_project_id' => $l->current_project_id,
            ])->all(),
            'meta' => $this->meta($rows),
            'links' => $this->links($rows),
        ]);
    }

    public function subcontractors(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.subcontractors.view') || $request->user()->can('site_diary.view') || $request->user()->can('site_diary.create'), 403);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 25);

        $rows = Subcontractor::query()
            ->active()
            ->search($search !== '' ? $search : null)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Subcontractor $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'trade' => $s->trade,
                'is_active' => (bool) $s->is_active,
            ])->all(),
            'meta' => $this->meta($rows),
            'links' => $this->links($rows),
        ]);
    }

    public function equipmentTypes(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('masters.equipment_types.view') || $request->user()->can('site_diary.view') || $request->user()->can('site_diary.create'), 403);

        $search = trim((string) $request->query('search', ''));
        $types = EquipmentType::query()
            ->active()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'is_active']);

        return response()->json([
            'data' => $types->map(fn (EquipmentType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_active' => (bool) $t->is_active,
            ])->all(),
        ]);
    }

    public function equipment(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('equipment.view') || $request->user()->can('equipment.register.view') || $request->user()->can('site_diary.view'), 403);

        $search = trim((string) $request->query('search', ''));
        $projectId = $request->integer('project_id') ?: null;
        $perPage = $this->perPage($request, 25);

        $rows = Equipment::query()
            ->active()
            ->with(['equipmentType:id,name'])
            ->search($search !== '' ? $search : null)
            ->when($projectId, fn ($q) => $q->where('location_project_id', $projectId))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (Equipment $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'registration_no' => $e->registration_no,
                'serial_no' => $e->serial_no,
                'type' => $e->equipmentType?->name,
                'status' => $e->status?->value ?? $e->status,
                'location_type' => $e->location_type?->value,
                'location_project_id' => $e->location_project_id,
                'location_name' => $e->location_name,
                'is_active' => (bool) $e->is_active,
            ])->all(),
            'meta' => $this->meta($rows),
            'links' => $this->links($rows),
        ]);
    }

    private function perPage(Request $request, int $default): int
    {
        return min(100, max(1, (int) $request->integer('per_page', $default)));
    }

    public function qualityChecklists(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', QualityChecklist::class);

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->perPage($request, 50);

        $rows = QualityChecklist::query()
            ->active()
            ->withCount('items')
            ->search($search !== '' ? $search : null)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($rows->items())->map(fn (QualityChecklist $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'discipline' => $c->discipline->value,
                'discipline_label' => $c->discipline->label(),
                'activity' => $c->activity,
                'category' => $c->discipline->value,
                'item_count' => (int) $c->items_count,
            ])->all(),
            'meta' => $this->meta($rows),
            'links' => $this->links($rows),
        ]);
    }

    public function expenseCategories(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('expenses.view') || $request->user()->can('expenses.create'), 403);

        $rows = ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'cost_head']);

        return response()->json([
            'data' => $rows->map(fn (ExpenseCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'cost_head' => $c->cost_head->value,
                'cost_head_label' => $c->cost_head->label(),
            ])->all(),
        ]);
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, mixed>
     */
    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, mixed>
     */
    private function links($paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url(max($paginator->lastPage(), 1)),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }
}
