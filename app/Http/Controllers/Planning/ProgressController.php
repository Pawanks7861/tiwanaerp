<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Boq\BoqStatus;
use App\Enums\Planning\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Services\Planning\ProgressLedgerService;
use App\Support\Math\Decimal;
use App\Support\Planning\TaskDelay;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only progress views over the task caches (recomputed from progress_entries) and the
 * ledger itself. Overall progress = average progress_percent of leaf tasks (each task counts
 * once, whatever its unit); quantities are only totalled per unit.
 */
class ProgressController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly ProgressLedgerService $ledger) {}

    public function __invoke(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'assignee' => ['nullable', 'integer'],
            'milestone' => ['nullable', 'integer'],
            'wbs' => ['nullable', 'string', 'max:30'],
            'delayed' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $tasks = ProjectTask::query()->where('project_id', $project->id)
            ->with(['unit:id,symbol', 'boqItem:id,item_code,name', 'assignee:id,name', 'milestone:id,name'])
            ->get()
            ->sort(fn (ProjectTask $a, ProjectTask $b) => strnatcmp($a->wbs_code, $b->wbs_code))
            ->values();
        $parentIds = $tasks->pluck('parent_id')->filter()->unique()->flip();
        $today = now();

        $rows = $tasks->map(fn (ProjectTask $t) => $this->row($t, $parentIds->has($t->id), $today));
        $filtered = $rows->filter(fn (array $r) => $this->matches($r, $filters))->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return Inertia::render('Planning/Progress', [
            'project' => ProjectHeader::for($project),
            'tasks' => (new LengthAwarePaginator($filtered->forPage($page, self::PER_PAGE)->values(), $filtered->count(), self::PER_PAGE, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ])),
            'kpis' => $this->kpis($rows),
            'filters' => $filters,
            'statuses' => TaskStatus::options(),
            'assignees' => $tasks->pluck('assignee')->filter()->unique('id')->sortBy('name')->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(),
            'milestones' => ProjectMilestone::query()->where('project_id', $project->id)->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn (ProjectMilestone $m) => ['value' => $m->id, 'label' => $m->name])->all(),
            'can' => ['boq' => $request->user()->can('boq.view')],
        ]);
    }

    /**
     * Executed quantity per line of the current approved BOQ, aggregated by line_uid so the
     * figures carry over BOQ revisions.
     */
    public function boq(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ProjectTask::class, $project]);
        Gate::authorize('boq.view');

        $executed = $this->ledger->executedByLine($project);

        $boqs = Boq::query()->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved)
            ->orderBy('boq_number')->get(['id', 'boq_number', 'title', 'version'])
            ->map(fn (Boq $boq) => [
                ...$boq->only(['id', 'boq_number', 'title', 'version']),
                'lines' => BoqItem::query()->where('boq_id', $boq->id)->with('unit:id,symbol')->orderBy('sort_order')
                    ->get(['id', 'item_code', 'name', 'unit_id', 'quantity', 'line_uid'])
                    ->map(function (BoqItem $i) use ($executed) {
                        $done = Decimal::of($executed[$i->line_uid] ?? '0');
                        $qty = Decimal::of($i->quantity);

                        return [
                            'id' => $i->id,
                            'item_code' => $i->item_code,
                            'name' => $i->name,
                            'unit' => $i->unit?->symbol,
                            'quantity' => $qty->toQuantity(),
                            'executed' => $done->toQuantity(),
                            'balance' => $qty->minus($done)->toQuantity(),
                            'percent' => $qty->isPositive() ? $done->dividedBy($qty)->times(100)->round(2)->toString() : null,
                        ];
                    })->all(),
            ]);

        return Inertia::render('Planning/BoqProgress', [
            'project' => ProjectHeader::for($project),
            'boqs' => $boqs->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProjectTask $t, bool $isParent, CarbonInterface $today): array
    {
        $planned = Decimal::of($t->planned_qty ?? '0')->isPositive() ? Decimal::of($t->planned_qty) : null;
        $completed = Decimal::of($t->completed_qty ?? '0');

        return [
            'id' => $t->id,
            'wbs_code' => $t->wbs_code,
            'name' => $t->name,
            'is_parent' => $isParent,
            'boq_item' => $t->boqItem ? trim($t->boqItem->item_code.' '.$t->boqItem->name) : null,
            'unit' => $t->unit?->symbol,
            'planned_qty' => $planned?->toQuantity(),
            'completed_qty' => $completed->toQuantity(),
            'balance_qty' => $planned?->minus($completed)->toQuantity(),
            'progress_percent' => Decimal::of($t->progress_percent ?? '0')->round(2)->toString(),
            'planned_finish' => $t->planned_finish?->toDateString(),
            'actual_start' => $t->actual_start?->toDateString(),
            'actual_finish' => $t->actual_finish?->toDateString(),
            'delay_days' => TaskDelay::days($t, $today),
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'assignee_id' => $t->assigned_to,
            'assignee' => $t->assignee?->name,
            'milestone_id' => $t->milestone_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $f
     */
    private function matches(array $r, array $f): bool
    {
        $term = mb_strtolower((string) ($f['search'] ?? ''));

        return (empty($f['status']) || $r['status'] === $f['status'])
            && (empty($f['assignee']) || (int) $r['assignee_id'] === (int) $f['assignee'])
            && (empty($f['milestone']) || (int) $r['milestone_id'] === (int) $f['milestone'])
            && (empty($f['wbs']) || str_starts_with((string) $r['wbs_code'], $f['wbs']))
            && (empty($f['delayed']) || $this->isDelayed($r))
            && ($term === '' || str_contains(mb_strtolower($r['wbs_code'].' '.$r['name'].' '.$r['boq_item']), $term));
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function isDelayed(array $r): bool
    {
        return $r['status'] === TaskStatus::Delayed->value
            || ($r['status'] !== TaskStatus::Completed->value && ($r['delay_days'] ?? 0) > 0);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function kpis(Collection $rows): array
    {
        $leaves = $rows->where('is_parent', false);
        $byUnit = $leaves->filter(fn ($r) => $r['planned_qty'] !== null && $r['unit'] !== null)
            ->groupBy('unit')
            ->map(fn (Collection $g, string $unit) => [
                'unit' => $unit,
                'planned' => Decimal::sum($g->pluck('planned_qty'))->toQuantity(),
                'completed' => Decimal::sum($g->pluck('completed_qty'))->toQuantity(),
                'tasks' => $g->count(),
            ])->values();

        return [
            'total' => $rows->count(),
            'leaf_tasks' => $leaves->count(),
            'by_status' => collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $s) => [$s->value => $rows->where('status', $s->value)->count()])->all(),
            'delayed' => $rows->filter(fn ($r) => $this->isDelayed($r))->count(),
            'overall_percent' => $leaves->isEmpty() ? '0.00' : Decimal::sum($leaves->pluck('progress_percent'))->dividedBy($leaves->count())->round(2)->toString(),
            'by_unit' => $byUnit->all(),
        ];
    }
}
