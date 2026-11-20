<?php

namespace App\Services\Planning;

use App\Enums\Boq\BoqStatus;
use App\Enums\Planning\MilestoneStatus;
use App\Enums\Planning\TaskStatus;
use App\Events\Planning\TaskAssigned;
use App\Models\Boq\BoqItem;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\PlanningBoqMappingService;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WBS / task maintenance and milestones. Progress (completed_qty, progress_percent, actual_cost)
 * is not edited here: it will come from DPRs and costing in later phases.
 */
class PlanningService
{
    public const WBS_PATTERN = '/^[A-Za-z0-9]+(\.[A-Za-z0-9]+)*$/';

    public function __construct(
        private readonly PlanningBoqMappingService $boqMaps,
    ) {}

    /**
     * Next free WBS code: "N" at the top level, "<parent>.N" below a parent.
     */
    public function suggestWbs(Project $project, ?ProjectTask $parent = null): string
    {
        $siblings = ProjectTask::query()
            ->where('project_id', $project->id)
            ->where('parent_id', $parent?->id)
            ->pluck('wbs_code');

        $prefix = $parent ? $parent->wbs_code.'.' : '';
        $max = 0;
        foreach ($siblings as $code) {
            $suffix = $prefix === '' ? $code : (str_starts_with($code, $prefix) ? substr($code, strlen($prefix)) : '');
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        $candidate = $prefix.($max + 1);
        while ($this->wbsTaken($project, $candidate)) {
            $candidate = $prefix.(++$max + 1);
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function saveTask(Project $project, array $data, ?ProjectTask $task = null): ProjectTask
    {
        return DB::transaction(function () use ($project, $data, $task) {
            $parent = isset($data['parent_id']) ? $this->taskOf($project, (int) $data['parent_id'], 'parent_id') : null;
            if ($task !== null && $parent !== null && $this->isSelfOrDescendant($task, $parent)) {
                throw ValidationException::withMessages(['parent_id' => 'A task cannot be placed under itself or one of its sub-tasks.']);
            }

            $wbs = strtoupper(trim((string) ($data['wbs_code'] ?? '')));
            $wbs = $wbs !== '' ? $wbs : $this->suggestWbs($project, $parent);
            if ($this->wbsTaken($project, $wbs, $task?->id)) {
                throw ValidationException::withMessages(['wbs_code' => "WBS code {$wbs} is already used in this project."]);
            }

            if (isset($data['milestone_id'])) {
                $this->milestoneOf($project, (int) $data['milestone_id']);
            }
            // BOQ is optional/historical only — never required for Planning.
            // Validate only when the client explicitly sets boq_item_id. Values written by
            // PlanningBoqMappingService (including draft BOQ maps) must not fail later updates.
            $boqItemId = array_key_exists('boq_item_id', $data)
                ? (blank($data['boq_item_id'] ?? null) ? null : (int) $data['boq_item_id'])
                : ($task?->boq_item_id);
            if ($boqItemId !== null && array_key_exists('boq_item_id', $data)) {
                $this->linkableBoqItem($project, $boqItemId);
            }

            $asHead = (bool) ($data['as_head'] ?? false) || ($parent === null && blank($data['unit_id'] ?? null) && ! isset($data['planned_qty']));
            $start = isset($data['planned_start']) && $data['planned_start'] !== null && $data['planned_start'] !== ''
                ? CarbonImmutable::parse($data['planned_start']) : null;
            $finish = isset($data['planned_finish']) && $data['planned_finish'] !== null && $data['planned_finish'] !== ''
                ? CarbonImmutable::parse($data['planned_finish']) : null;
            if ($start && $finish && $finish->lt($start)) {
                throw ValidationException::withMessages(['planned_finish' => 'Planned finish cannot be before planned start.']);
            }

            // Activities (non-head) require schedule + unit + total qty when creating/updating as activity.
            $isActivity = ! $asHead && ($parent !== null || filled($data['unit_id'] ?? null) || filled($data['planned_qty'] ?? null)
                || ($task !== null && $task->parent_id !== null));
            if ($isActivity && $task === null) {
                $errors = [];
                if ($start === null) {
                    $errors['planned_start'] = 'Planned start is required for an activity.';
                }
                if ($finish === null) {
                    $errors['planned_finish'] = 'Planned end is required for an activity.';
                }
                if (blank($data['unit_id'] ?? null)) {
                    $errors['unit_id'] = 'Choose a unit for the activity.';
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }

            $plannedQty = Decimal::of((string) ($data['planned_qty'] ?? ($task?->planned_qty ?? '0')));
            // Empty / null hold from the form must clear to 0 — do not fall back to the previous value.
            $holdQty = array_key_exists('hold_quantity', $data)
                ? Decimal::of((string) (filled($data['hold_quantity']) ? $data['hold_quantity'] : '0'))
                : Decimal::of((string) ($task?->hold_quantity ?? '0'));
            if ($holdQty->isNegative()) {
                throw ValidationException::withMessages(['hold_quantity' => 'Hold quantity cannot be negative.']);
            }
            $executed = Decimal::of((string) ($task?->completed_qty ?? '0'));
            if ($holdQty->plus($executed)->greaterThan($plannedQty) && $plannedQty->isPositive()) {
                throw ValidationException::withMessages(['hold_quantity' => 'Hold quantity cannot exceed remaining planned quantity.']);
            }

            $task ??= new ProjectTask;
            $previousAssignee = $task->exists ? $task->getOriginal('assigned_to') : null;
            $task->fill([
                'parent_id' => $parent?->id,
                'milestone_id' => array_key_exists('milestone_id', $data) ? ($data['milestone_id'] ?? null) : $task->milestone_id,
                'boq_item_id' => $boqItemId,
                'wbs_code' => $wbs,
                'name' => $data['name'],
                'description' => array_key_exists('description', $data) ? ($data['description'] ?? null) : $task->description,
                'assigned_to' => array_key_exists('assigned_to', $data) ? ($data['assigned_to'] ?? null) : $task->assigned_to,
                'priority' => $data['priority'] ?? ($task->priority?->value ?? 'medium'),
                'planned_start' => $start?->toDateString() ?? (array_key_exists('planned_start', $data) ? null : $task->planned_start?->toDateString()),
                'planned_finish' => $finish?->toDateString() ?? (array_key_exists('planned_finish', $data) ? null : $task->planned_finish?->toDateString()),
                'duration_days' => $start && $finish ? (int) $start->diffInDays($finish) + 1 : null,
                'unit_id' => array_key_exists('unit_id', $data) ? ($data['unit_id'] ?? null) : $task->unit_id,
                'planned_qty' => $plannedQty->toQuantity(),
                'daily_target_qty' => array_key_exists('daily_target_qty', $data)
                    ? (filled($data['daily_target_qty'] ?? null) ? Decimal::of((string) $data['daily_target_qty'])->toQuantity() : null)
                    : $task->daily_target_qty,
                'hold_quantity' => $holdQty->toQuantity(),
                'sort_order' => $data['sort_order'] ?? ($task->exists ? $task->sort_order : $this->nextSort($project, $parent)),
            ]);
            if (array_key_exists('budget_amount', $data)) {
                $task->budget_amount = Decimal::of((string) ($data['budget_amount'] ?? '0'))->toMoney();
            }
            if (! $task->exists) {
                $task->forceFill(['project_id' => $project->id, 'status' => TaskStatus::NotStarted]);
            }
            $task->save();
            if ($task->assigned_to && (int) $task->assigned_to !== (int) $previousAssignee) {
                $taskId = $task->id;
                DB::afterCommit(fn () => TaskAssigned::dispatch(ProjectTask::query()->findOrFail($taskId)));
            }

            return $task;
        });
    }

    public function changeStatus(ProjectTask $task, TaskStatus $target): ProjectTask
    {
        if ($task->status === $target) {
            return $task;
        }
        if (! $task->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "A task that is {$task->status->label()} cannot be moved to {$target->label()}.",
            ]);
        }

        $today = now()->toDateString();
        $task->forceFill([
            'status' => $target,
            'actual_start' => $task->actual_start ?? (in_array($target, [TaskStatus::InProgress, TaskStatus::Delayed, TaskStatus::Completed], true) ? $today : null),
            'actual_finish' => $target === TaskStatus::Completed ? $today : null,
        ])->save();

        return $task;
    }

    public function deleteTask(ProjectTask $task, ?User $user = null): void
    {
        DB::transaction(function () use ($task, $user) {
            if ($task->children()->exists()) {
                throw ValidationException::withMessages(['task' => 'Delete or move the sub-tasks first.']);
            }

            TaskDependency::query()
                ->where(fn (Builder $q) => $q->where('predecessor_id', $task->id)->orWhere('successor_id', $task->id))
                ->delete();

            // Soft-delete does not cascade maps; release BOQ tags so qty becomes available again.
            $this->boqMaps->releaseMapsForTask($task, $user);

            // Free the WBS code for reuse; the unique index also covers soft-deleted rows.
            $task->wbs_code = mb_substr($task->wbs_code, 0, 30 - strlen('~'.$task->id)).'~'.$task->id;
            $task->save();
            $task->delete();
        });
    }

    /**
     * @param  array{name: string, due_date?: string|null, billing_percent?: string|null, sort_order?: int|null}  $data
     */
    public function saveMilestone(Project $project, array $data, ?ProjectMilestone $milestone = null): ProjectMilestone
    {
        return DB::transaction(function () use ($project, $data, $milestone) {
            $percent = Decimal::of((string) ($data['billing_percent'] ?? '0'));
            $others = ProjectMilestone::query()
                ->where('project_id', $project->id)
                ->when($milestone, fn (Builder $q) => $q->whereKeyNot($milestone->id))
                ->lockForUpdate()
                ->pluck('billing_percent');

            if (Decimal::sum($others)->plus($percent)->greaterThan(100)) {
                throw ValidationException::withMessages(['billing_percent' => 'Billing percentages of all milestones cannot exceed 100%.']);
            }

            $milestone ??= new ProjectMilestone;
            $milestone->fill([
                'name' => $data['name'],
                'planned_start' => $data['planned_start'] ?? null,
                'planned_end' => $data['planned_end'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'billing_percent' => $percent->round(Decimal::PERCENT_SCALE)->toString(),
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'sort_order' => $data['sort_order'] ?? ($milestone->exists ? $milestone->sort_order : $others->count() + 1),
            ]);
            if (! $milestone->exists) {
                $milestone->forceFill(['project_id' => $project->id, 'status' => MilestoneStatus::Pending]);
            }
            $milestone->save();

            return $milestone;
        });
    }

    public function setMilestoneCompleted(ProjectMilestone $milestone, bool $completed): ProjectMilestone
    {
        $milestone->forceFill([
            'status' => $completed ? MilestoneStatus::Completed : MilestoneStatus::Pending,
            'completed_at' => $completed ? ($milestone->completed_at ?? now()->toDateString()) : null,
        ])->save();

        return $milestone;
    }

    public function deleteMilestone(ProjectMilestone $milestone): void
    {
        if ($milestone->tasks()->exists()) {
            throw ValidationException::withMessages(['milestone' => 'Unlink the tasks of this milestone first.']);
        }

        $milestone->delete();
    }

    /**
     * BOQ lines that tasks may link to: lines of the project's current approved BOQs.
     *
     * @return Builder<BoqItem>
     */
    public function linkableBoqItems(Project $project): Builder
    {
        return BoqItem::query()->whereHas('boq', fn (Builder $b) => $b
            ->where('project_id', $project->id)
            ->where('status', BoqStatus::Approved)
            ->where('is_current', true));
    }

    private function linkableBoqItem(Project $project, int $id): BoqItem
    {
        return $this->linkableBoqItems($project)->find($id)
            ?? throw ValidationException::withMessages(['boq_item_id' => 'Choose a line of this project\'s current approved BOQ.']);
    }

    private function taskOf(Project $project, int $id, string $field): ProjectTask
    {
        return ProjectTask::query()->where('project_id', $project->id)->find($id)
            ?? throw ValidationException::withMessages([$field => 'Choose a task of this project.']);
    }

    private function milestoneOf(Project $project, int $id): ProjectMilestone
    {
        return ProjectMilestone::query()->where('project_id', $project->id)->find($id)
            ?? throw ValidationException::withMessages(['milestone_id' => 'Choose a milestone of this project.']);
    }

    private function wbsTaken(Project $project, string $code, ?int $ignoreId = null): bool
    {
        return ProjectTask::query()->withTrashed()
            ->where('project_id', $project->id)
            ->where('wbs_code', $code)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function nextSort(Project $project, ?ProjectTask $parent): int
    {
        return (int) ProjectTask::query()->where('project_id', $project->id)->where('parent_id', $parent?->id)->max('sort_order') + 1;
    }

    /**
     * Whether $candidate is $task itself or sits anywhere below it.
     */
    private function isSelfOrDescendant(ProjectTask $task, ProjectTask $candidate): bool
    {
        $seen = [];
        $current = $candidate;
        while ($current !== null) {
            if ((int) $current->id === (int) $task->id) {
                return true;
            }
            if (isset($seen[$current->id]) || $current->parent_id === null) {
                return false;
            }
            $seen[$current->id] = true;
            $current = ProjectTask::query()->find($current->parent_id);
        }

        return false;
    }
}
