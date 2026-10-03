<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Planning\ProjectMilestone;
use App\Models\Projects\Project;
use App\Services\Planning\PlanningService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MilestoneController extends Controller
{
    public function __construct(private readonly PlanningService $planning) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectMilestone::class, $project]);

        $user = $request->user();
        $milestones = $project->milestones()->withCount('tasks')->orderBy('sort_order')->orderBy('due_date')->get();

        return Inertia::render('Planning/Milestones', [
            'project' => ProjectHeader::for($project),
            'milestones' => $milestones->map(fn (ProjectMilestone $m) => [
                ...$m->only(['id', 'name', 'billing_percent', 'sort_order', 'tasks_count']),
                'due_date' => $m->due_date?->toDateString(),
                'completed_at' => $m->completed_at?->toDateString(),
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
            ])->all(),
            'billing_total' => Decimal::sum($milestones->pluck('billing_percent'))->round(Decimal::PERCENT_SCALE)->toString(),
            'can' => [
                'create' => $user->can('create', [ProjectMilestone::class, $project]),
                'update' => $user->can('planning.update'),
                'delete' => $user->can('planning.delete'),
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [ProjectMilestone::class, $project]);

        $milestone = $this->planning->saveMilestone($project, $this->validated($request));

        return back()->with('success', "Milestone {$milestone->name} added.");
    }

    public function update(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        Gate::authorize('update', $milestone);

        $this->planning->saveMilestone($project, $this->validated($request), $milestone);

        return back()->with('success', 'Milestone updated.');
    }

    public function complete(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        Gate::authorize('update', $milestone);

        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $this->planning->setMilestoneCompleted($milestone, (bool) $data['completed']);

        return back()->with('success', $data['completed'] ? 'Milestone marked complete.' : 'Milestone reopened.');
    }

    public function destroy(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        Gate::authorize('delete', $milestone);

        $this->planning->deleteMilestone($milestone);

        return back()->with('success', 'Milestone deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $percent = $request->input('billing_percent');
        if (is_int($percent) || is_float($percent)) {
            $request->merge(['billing_percent' => is_float($percent) ? rtrim(rtrim(sprintf('%.6F', $percent), '0'), '.') : (string) $percent]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date'],
            'billing_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
