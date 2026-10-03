<?php

namespace App\Services\Planning;

use App\Enums\Planning\TaskStatus;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Task progress caches, always recomputed from the progress ledger (never incremented):
 *
 * - completed_qty    = SUM(progress_entries.quantity) for the task.
 * - progress_percent = completed / planned × 100 (Decimal, 4 dp, capped at 100). A completed task
 *                      is 100; without a planned quantity the task is tracked by status only
 *                      (100 when completed, else 0).
 * - actual_start     = date of the first progress entry; set once and never cleared.
 * - Reaching the planned quantity completes the task and sets actual_finish to the date of the
 *   latest entry. A reversal that drops a quantity-completed task below plan reopens it
 *   (in progress, or delayed when overdue) and clears actual_finish. A task completed manually
 *   below its planned quantity is left completed.
 * - First progress moves a not-started task to in progress; an overdue in-progress task becomes
 *   delayed. On-hold tasks keep their status (caches still update); delayed is never cleared
 *   automatically.
 */
class TaskProgressService
{
    public function recompute(ProjectTask $task): ProjectTask
    {
        $completed = self::sum(ProgressEntry::query()->where('task_id', $task->id));
        if ($completed->isNegative()) {
            $completed = Decimal::zero();
        }

        $planned = Decimal::of($task->planned_qty ?? '0');
        $hasPlan = $planned->isPositive();
        $wasQtyComplete = $hasPlan && Decimal::of($task->completed_qty ?? '0')->greaterThanOrEqual($planned);
        $isQtyComplete = $hasPlan && $completed->greaterThanOrEqual($planned);

        $status = $task->status;
        $actualStart = $task->actual_start?->toDateString();
        $actualFinish = $task->actual_finish?->toDateString();

        if ($actualStart === null && $completed->isPositive()) {
            $actualStart = $this->firstEntryDate($task);
        }

        if ($status !== TaskStatus::OnHold) {
            $overdue = $task->planned_finish !== null && $task->planned_finish->toDateString() < now()->toDateString();

            if ($isQtyComplete) {
                if ($status !== TaskStatus::Completed || $actualFinish === null) {
                    $actualFinish = $this->lastEntryDate($task);
                }
                $status = TaskStatus::Completed;
            } elseif ($status === TaskStatus::Completed && $wasQtyComplete) {
                $status = $overdue ? TaskStatus::Delayed : TaskStatus::InProgress;
                $actualFinish = null;
            } elseif ($status === TaskStatus::NotStarted && $completed->isPositive()) {
                $status = $overdue ? TaskStatus::Delayed : TaskStatus::InProgress;
            } elseif ($status === TaskStatus::InProgress && $overdue) {
                $status = TaskStatus::Delayed;
            }
        }

        $percent = Decimal::of($status === TaskStatus::Completed ? '100' : '0');
        if ($hasPlan && $status !== TaskStatus::Completed) {
            $percent = $completed->dividedBy($planned)->times(100);
            if ($percent->greaterThan(100)) {
                $percent = Decimal::of('100');
            }
        }

        $task->forceFill([
            'completed_qty' => $completed->toQuantity(),
            'progress_percent' => $percent->round(Decimal::PERCENT_SCALE)->toString(),
            'status' => $status,
            'actual_start' => $actualStart,
            'actual_finish' => $actualFinish,
        ])->save();

        return $task;
    }

    /**
     * Exact SUM of ledger quantities (SQLite may hand back a float, so round to the qty scale).
     *
     * @param  Builder<ProgressEntry>  $query
     */
    public static function sum(Builder $query): Decimal
    {
        $value = $query->toBase()->selectRaw('COALESCE(SUM(quantity), 0) as total')->value('total');

        return Decimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) ($value ?? '0'))->round(Decimal::QTY_SCALE);
    }

    private function firstEntryDate(ProjectTask $task): ?string
    {
        return $this->netPositiveEntries($task)->min();
    }

    private function lastEntryDate(ProjectTask $task): ?string
    {
        return $this->netPositiveEntries($task)->max();
    }

    /**
     * Forward postings that have not been reversed.
     *
     * @return Collection<int, string>
     */
    private function netPositiveEntries(ProjectTask $task)
    {
        return ProgressEntry::query()
            ->where('task_id', $task->id)
            ->whereNull('reverses_id')
            ->whereNotIn('id', ProgressEntry::query()->whereNotNull('reverses_id')->select('reverses_id'))
            ->pluck('entry_date')
            ->map(fn ($date) => substr((string) $date, 0, 10));
    }
}
