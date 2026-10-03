<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Projects\Project;
use App\Services\Equipment\EquipmentUsageService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentUsageController extends Controller
{
    public function __construct(private readonly EquipmentUsageService $usage) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [EquipmentUsageLog::class, $project]);
        $user = $request->user();

        $filters = $request->validate([
            'state' => ['nullable', 'in:posted,unposted'],
            'assignment_id' => ['nullable', 'integer'],
        ]);

        $page = $project->equipmentUsageLogs()
            ->with(['assignment:id,equipment_id,rate_basis,rate', 'assignment.equipment:id,code,name', 'task:id,wbs_code,name', 'poster:id,name'])
            ->when(($filters['state'] ?? null) === 'posted', fn ($q) => $q->whereNotNull('posted_at'))
            ->when(($filters['state'] ?? null) === 'unposted', fn ($q) => $q->whereNull('posted_at'))
            ->when($filters['assignment_id'] ?? null, fn ($q, $id) => $q->where('equipment_assignment_id', $id))
            ->latest('log_date')->latest('id')->paginate(50)->withQueryString();
        $page->getCollection()->each->setRelation('project', $project);

        return Inertia::render('Equipment/Usage', [
            'project' => ProjectHeader::for($project),
            'logs' => $page->through(fn (EquipmentUsageLog $l) => [
                'id' => $l->id,
                'equipment_assignment_id' => $l->equipment_assignment_id,
                'equipment' => $l->assignment?->equipment ? "{$l->assignment->equipment->code} · {$l->assignment->equipment->name}" : null,
                'log_date' => $l->log_date?->toDateString(),
                'task_id' => $l->task_id,
                'task' => $l->task ? trim($l->task->wbs_code.' '.$l->task->name) : null,
                ...$l->only(['opening_meter', 'closing_meter', 'working_hours', 'idle_hours', 'cost_amount', 'remarks']),
                'rate_basis' => $l->assignment?->rate_basis?->label(),
                'rate' => $l->assignment?->rate,
                'estimated_cost' => $l->assignment ? $this->usage->cost($l->assignment, $l)->toMoney() : null,
                'posted' => $l->isPosted(),
                'posted_by' => $l->poster?->name,
                'can_update' => ! $l->isPosted() && $user->can('update', $l),
                'can_reverse' => $l->isPosted() && $user->can('reverse', $l),
            ]),
            'filters' => $filters,
            'unpostedTotal' => Decimal::sum(
                $project->equipmentUsageLogs()->whereNull('posted_at')->with('assignment:id,rate_basis,rate')->get()
                    ->map(fn (EquipmentUsageLog $l) => $this->usage->cost($l->assignment, $l))->all()
            )->toMoney(),
            'options' => [
                'assignments' => $project->equipmentAssignments()->with('equipment:id,code,name')->latest('issue_date')->get()
                    ->map(fn (EquipmentAssignment $a) => [
                        'value' => $a->id,
                        'label' => $a->equipment ? "{$a->equipment->code} · {$a->equipment->name}" : "#{$a->id}",
                        'description' => $a->issue_date->toDateString().' → '.($a->return_date?->toDateString() ?? 'active').' · '.$a->rate_basis->label().' '.$a->rate,
                        'issue_date' => $a->issue_date->toDateString(),
                        'return_date' => $a->return_date?->toDateString(),
                        'task_id' => $a->task_id,
                    ])->all(),
                'tasks' => ProcurementPresenter::taskOptions($project),
            ],
            'today' => now()->toDateString(),
            'can' => [
                'create' => $user->can('create', [EquipmentUsageLog::class, $project]),
                'post' => $user->can('post', [EquipmentUsageLog::class, $project]),
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [EquipmentUsageLog::class, $project]);

        $this->usage->create($project, $this->validated($request, true));

        return back()->with('success', 'Usage logged. Post it to charge the project.');
    }

    public function update(Request $request, Project $project, EquipmentUsageLog $equipmentUsageLog): RedirectResponse
    {
        Gate::authorize('update', $equipmentUsageLog);

        $this->usage->update($equipmentUsageLog, $this->validated($request, false));

        return back()->with('success', 'Usage log updated.');
    }

    public function destroy(Project $project, EquipmentUsageLog $equipmentUsageLog): RedirectResponse
    {
        Gate::authorize('delete', $equipmentUsageLog);

        $this->usage->delete($equipmentUsageLog);

        return back()->with('success', 'Usage log deleted.');
    }

    public function post(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('post', [EquipmentUsageLog::class, $project]);

        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer']])['ids'];
        $count = $this->usage->post($project, $ids, $request->user());

        return back()->with('success', $count > 0 ? "{$count} usage ".str('log')->plural($count).' posted; equipment cost charged.' : 'Nothing to post.');
    }

    public function reverse(Request $request, Project $project, EquipmentUsageLog $equipmentUsageLog): RedirectResponse
    {
        Gate::authorize('reverse', $equipmentUsageLog);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->usage->reverse($equipmentUsageLog, $request->user(), $reason);

        return back()->with('success', 'Posting reversed; the log can be corrected and posted again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? ['equipment_assignment_id' => ['required', 'integer']] : []),
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'task_id' => ['nullable', 'integer'],
            'opening_meter' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'closing_meter' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'working_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'idle_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:24'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['equipment_assignment_id' => 'equipment']);
    }
}
