<?php

namespace App\Http\Controllers\Equipment;

use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\RateBasis;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Labour\Labour;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentAssignmentController extends Controller
{
    public function __construct(private readonly EquipmentAssignmentService $assignments) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [EquipmentAssignment::class, $project]);
        $user = $request->user();

        $filters = $request->validate(['status' => ['nullable', Rule::enum(AssignmentStatus::class)]]);

        $page = $project->equipmentAssignments()
            ->with(['equipment:id,code,name,status', 'site:id,name', 'task:id,wbs_code,name', 'operator:id,name'])
            ->withCount('usageLogs')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->latest('issue_date')->latest('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('Equipment/Assignments', [
            'project' => ProjectHeader::for($project),
            'assignments' => $page->through(fn (EquipmentAssignment $a) => [
                'id' => $a->id,
                'equipment' => $a->equipment ? "{$a->equipment->code} · {$a->equipment->name}" : null,
                'equipment_status' => $a->equipment?->status?->label(),
                'site_id' => $a->site_id,
                'site' => $a->site?->name,
                'task_id' => $a->task_id,
                'task' => $a->task ? trim($a->task->wbs_code.' '.$a->task->name) : null,
                'operator_labour_id' => $a->operator_labour_id,
                'operator' => $a->operator?->name ?? $a->operator_name,
                'operator_name' => $a->operator_name,
                'issue_date' => $a->issue_date?->toDateString(),
                'return_date' => $a->return_date?->toDateString(),
                'rate_basis' => $a->rate_basis->value,
                'rate_basis_label' => $a->rate_basis->label(),
                'rate' => $a->rate,
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'remarks' => $a->remarks,
                'usage_logs_count' => $a->usage_logs_count,
                'can_update' => $a->isActive() && $user->can('update', $a),
            ]),
            'filters' => $filters,
            'statuses' => AssignmentStatus::options(),
            'options' => [
                'equipment' => Equipment::query()->active()->where('status', EquipmentStatus::Available)->orderBy('name')
                    ->get(['id', 'code', 'name', 'hourly_rate', 'daily_rate'])
                    ->map(fn (Equipment $e) => ['value' => $e->id, 'label' => $e->name, 'description' => $e->code, 'hourly_rate' => $e->hourly_rate, 'daily_rate' => $e->daily_rate])->all(),
                'sites' => ProcurementPresenter::siteOptions($project),
                'tasks' => ProcurementPresenter::taskOptions($project),
                'operators' => Labour::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Labour $l) => ['value' => $l->id, 'label' => $l->name, 'description' => $l->code])->all(),
                'rate_bases' => RateBasis::options(),
            ],
            'today' => now()->toDateString(),
            'can' => ['create' => $user->can('create', [EquipmentAssignment::class, $project])],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [EquipmentAssignment::class, $project]);

        $data = $request->validate([
            'equipment_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'rate_basis' => ['required', Rule::enum(RateBasis::class)],
            'rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999'],
            ...$this->detailRules(),
        ], [], ['equipment_id' => 'equipment']);

        $this->assignments->issue($project, $data);

        return back()->with('success', 'Equipment issued to the project.');
    }

    public function update(Request $request, Project $project, EquipmentAssignment $equipmentAssignment): RedirectResponse
    {
        Gate::authorize('update', $equipmentAssignment);

        $this->assignments->update($equipmentAssignment, $request->validate($this->detailRules()));

        return back()->with('success', 'Assignment updated.');
    }

    public function returnEquipment(Request $request, Project $project, EquipmentAssignment $equipmentAssignment): RedirectResponse
    {
        Gate::authorize('returnEquipment', $equipmentAssignment);

        $data = $request->validate([
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assignments->returnEquipment($equipmentAssignment, $data, $request->user());

        return back()->with('success', 'Equipment returned.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function detailRules(): array
    {
        return [
            'site_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'operator_labour_id' => ['nullable', 'integer'],
            'operator_name' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
