<?php

namespace App\Services\Planning;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\BoqItem;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprItem;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sole writer of the append-only progress ledger. DPR approval posts one entry per DPR work line
 * that has a quantity and a task and/or BOQ line; correcting an approved DPR posts reversals.
 *
 * - Idempotent: every row carries a deterministic posting_ref (unique in the database):
 *   "dpr:{id}:r{revision}:item:{item}" for postings, "reversal:{entry}" for reversals.
 * - Over-progress is refused: a task cannot exceed its planned quantity and a BOQ line (by
 *   line_uid, across revisions) cannot exceed the quantity of its current approved revision.
 * - Task and BOQ rows are locked in id order before the checks so concurrent approvals serialise.
 */
class ProgressLedgerService
{
    public function __construct(private readonly TaskProgressService $taskProgress) {}

    public static function postingRef(Dpr $dpr, DprItem $item): string
    {
        return "dpr:{$dpr->id}:r{$dpr->revision}:item:{$item->id}";
    }

    /**
     * Post an approved DPR (call inside the approval transaction). Lines already posted for this
     * revision are skipped, so a retry changes nothing.
     */
    public function postDpr(Dpr $dpr, ?int $userId = null): void
    {
        DB::transaction(function () use ($dpr, $userId) {
            $project = Project::query()->findOrFail($dpr->project_id);
            $items = $this->items($dpr);
            $this->lockTargets($project, $items);

            $posted = ProgressEntry::query()->whereIn('posting_ref', $items->map(fn (DprItem $i) => self::postingRef($dpr, $i)))->pluck('posting_ref')->all();
            $pending = $items->reject(fn (DprItem $i) => in_array(self::postingRef($dpr, $i), $posted, true));
            $rows = $this->check($project, $pending);

            foreach ($rows as $row) {
                /** @var DprItem $item */
                $item = $row['item'];
                $entry = new ProgressEntry;
                $entry->forceFill([
                    'project_id' => $project->id,
                    'task_id' => $row['task']?->id,
                    'boq_item_id' => $row['boq']?->id,
                    'boq_line_uid' => $row['boq']?->line_uid,
                    'entry_date' => $dpr->dpr_date->toDateString(),
                    'quantity' => $row['qty']->toQuantity(),
                    'source_type' => $item->getMorphClass(),
                    'source_id' => $item->id,
                    'posting_ref' => self::postingRef($dpr, $item),
                    'created_by' => $userId ?? Auth::id(),
                ])->save();
            }

            $this->snapshot($dpr, posted: true);
            $this->recomputeTasks($items->pluck('task_id'));
        });
    }

    /**
     * Reverse every live posting of a DPR (correction). Reversal rows keep the original entry date
     * so date-based cumulative figures reflect the correction.
     */
    public function reverseDpr(Dpr $dpr, ?int $userId = null): int
    {
        return DB::transaction(function () use ($dpr, $userId) {
            $itemIds = DprItem::query()->where('dpr_id', $dpr->id)->pluck('id');
            $forward = ProgressEntry::query()
                ->where('source_type', (new DprItem)->getMorphClass())
                ->whereIn('source_id', $itemIds)
                ->whereNull('reverses_id')
                ->whereNotIn('id', ProgressEntry::query()->whereNotNull('reverses_id')->select('reverses_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($forward as $original) {
                $reversal = new ProgressEntry;
                $reversal->forceFill([
                    'project_id' => $original->project_id,
                    'task_id' => $original->task_id,
                    'boq_item_id' => $original->boq_item_id,
                    'boq_line_uid' => $original->boq_line_uid,
                    'entry_date' => $original->entry_date->toDateString(),
                    'quantity' => Decimal::of($original->quantity)->negate()->toQuantity(),
                    'source_type' => $original->source_type,
                    'source_id' => $original->source_id,
                    'posting_ref' => "reversal:{$original->id}",
                    'reverses_id' => $original->id,
                    'created_by' => $userId ?? Auth::id(),
                ])->save();
            }

            $this->recomputeTasks($forward->pluck('task_id'));

            return $forward->count();
        });
    }

    /**
     * Early, unlocked over-progress check (DPR submit). Approval re-checks under lock.
     */
    public function assertPostable(Dpr $dpr): void
    {
        $this->check(Project::query()->findOrFail($dpr->project_id), $this->items($dpr));
    }

    /**
     * Refresh planned / cumulative / balance on the DPR lines. Before posting the cumulative
     * includes this DPR's own quantities; after posting the ledger already contains them.
     */
    public function snapshot(Dpr $dpr, bool $posted = false): void
    {
        $project = Project::query()->findOrFail($dpr->project_id);
        $items = $this->items($dpr);
        $ownByTask = [];
        $ownByLine = [];

        foreach ($items as $item) {
            $uid = $item->boq_item_id ? BoqItem::query()->whereKey($item->boq_item_id)->value('line_uid') : null;
            $item->setAttribute('boq_line_uid', $uid);
            if (! $posted && $item->task_id) {
                $ownByTask[$item->task_id] = Decimal::of($ownByTask[$item->task_id] ?? '0')->plus($item->executed_qty);
            }
            if (! $posted && $uid) {
                $ownByLine[$uid] = Decimal::of($ownByLine[$uid] ?? '0')->plus($item->executed_qty);
            }
        }

        foreach ($items as $item) {
            $planned = null;
            $cumulative = null;
            $uid = $item->boq_line_uid;
            $task = $item->task_id ? ProjectTask::query()->withTrashed()->find($item->task_id) : null;
            $taskPlan = $task !== null && Decimal::of($task->planned_qty ?? '0')->isPositive() ? Decimal::of($task->planned_qty) : null;

            // A task without a planned quantity (0) is measured against its BOQ line when it has one.
            if ($task !== null && ($taskPlan !== null || $uid === null)) {
                $planned = $taskPlan;
                $cumulative = TaskProgressService::sum(ProgressEntry::query()->where('task_id', $item->task_id))->plus($ownByTask[$item->task_id] ?? '0');
            } elseif ($uid) {
                $line = $this->currentLine($project, $uid);
                $planned = $line ? Decimal::of($line->quantity) : null;
                $cumulative = TaskProgressService::sum(ProgressEntry::query()->where('project_id', $project->id)->where('boq_line_uid', $uid))->plus($ownByLine[$uid] ?? '0');
            }

            $item->forceFill([
                'boq_line_uid' => $uid,
                'planned_qty' => $planned?->toQuantity(),
                'cumulative_qty' => $cumulative?->toQuantity(),
                'balance_qty' => $planned !== null && $cumulative !== null ? $planned->minus($cumulative)->toQuantity() : null,
            ])->save();
        }
    }

    /**
     * Executed quantity per BOQ line_uid of a project (all revisions).
     *
     * @return array<string, string>
     */
    public function executedByLine(Project $project): array
    {
        return ProgressEntry::query()
            ->where('project_id', $project->id)
            ->whereNotNull('boq_line_uid')
            ->groupBy('boq_line_uid')
            ->toBase()
            ->selectRaw('boq_line_uid, COALESCE(SUM(quantity), 0) as total')
            ->pluck('total', 'boq_line_uid')
            ->map(fn ($v) => Decimal::of(is_float($v) ? sprintf('%.6F', $v) : (string) $v)->toQuantity())
            ->all();
    }

    /**
     * @return Collection<int, DprItem>
     */
    private function items(Dpr $dpr): Collection
    {
        return DprItem::query()->where('dpr_id', $dpr->id)->orderBy('sort_order')->orderBy('id')->get()->values();
    }

    /**
     * @param  Collection<int, DprItem>  $items
     */
    private function lockTargets(Project $project, Collection $items): void
    {
        $taskIds = $items->pluck('task_id')->filter()->unique()->sort()->values();
        if ($taskIds->isNotEmpty()) {
            ProjectTask::query()->where('project_id', $project->id)->whereKey($taskIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $uids = BoqItem::query()->whereKey($items->pluck('boq_item_id')->filter()->unique())->pluck('line_uid')->unique();
        if ($uids->isNotEmpty()) {
            $this->currentLines($project)->whereIn('line_uid', $uids)->orderBy('id')->lockForUpdate()->get(['boq_items.id']);
        }
    }

    /**
     * Validates project, task, BOQ line and unit of every line and refuses over-progress.
     *
     * @param  Collection<int, DprItem>  $items
     * @return list<array{item: DprItem, task: ?ProjectTask, boq: ?BoqItem, qty: Decimal}>
     */
    private function check(Project $project, Collection $items): array
    {
        $rows = [];
        $byTask = [];
        $byLine = [];

        foreach ($items->values() as $index => $item) {
            $label = 'Line '.($item->sort_order ?: $index + 1);
            $qty = Decimal::of($item->executed_qty);

            $task = $item->task_id ? ProjectTask::query()->where('project_id', $project->id)->find($item->task_id) : null;
            if ($item->task_id && $task === null) {
                throw ValidationException::withMessages(['items' => "{$label}: the task is not an active task of this project."]);
            }

            $boq = $item->boq_item_id
                ? BoqItem::query()->whereKey($item->boq_item_id)->whereHas('boq', fn (Builder $q) => $q->where('project_id', $project->id))->first()
                : null;
            if ($item->boq_item_id && $boq === null) {
                throw ValidationException::withMessages(['items' => "{$label}: the BOQ item does not belong to this project."]);
            }

            if (($task?->unit_id !== null && (int) $task->unit_id !== (int) $item->unit_id) || ($boq?->unit_id !== null && (int) $boq->unit_id !== (int) $item->unit_id)) {
                throw ValidationException::withMessages(['items' => "{$label}: the unit does not match the task / BOQ item unit."]);
            }

            if (! $qty->isPositive() || ($task === null && $boq === null)) {
                continue;
            }

            $rows[] = ['item' => $item, 'task' => $task, 'boq' => $boq, 'qty' => $qty];
            if ($task) {
                $byTask[$task->id] = Decimal::of($byTask[$task->id] ?? '0')->plus($qty);
            }
            if ($boq) {
                $byLine[$boq->line_uid] = Decimal::of($byLine[$boq->line_uid] ?? '0')->plus($qty);
            }
        }

        foreach ($byTask as $taskId => $qty) {
            $task = ProjectTask::query()->findOrFail($taskId);
            $planned = Decimal::of($task->planned_qty ?? '0');
            if (! $planned->isPositive()) {
                continue;
            }
            $done = TaskProgressService::sum(ProgressEntry::query()->where('task_id', $taskId));
            if ($done->plus($qty)->greaterThan($planned)) {
                throw ValidationException::withMessages(['items' => sprintf(
                    'Over-progress on task %s %s: planned %s, already executed %s, this DPR %s. Correct the quantity or revise the plan.',
                    $task->wbs_code, $task->name, $planned->toQuantity(), $done->toQuantity(), $qty->toQuantity(),
                )]);
            }
        }

        foreach ($byLine as $uid => $qty) {
            $line = $this->currentLine($project, $uid);
            if ($line === null || ! Decimal::of($line->quantity)->isPositive()) {
                continue;
            }
            $done = TaskProgressService::sum(ProgressEntry::query()->where('project_id', $project->id)->where('boq_line_uid', $uid));
            if ($done->plus($qty)->greaterThan($line->quantity)) {
                throw ValidationException::withMessages(['items' => sprintf(
                    'Over-progress on BOQ item %s: BOQ quantity %s, already executed %s, this DPR %s.',
                    trim($line->item_code.' '.$line->name), Decimal::of($line->quantity)->toQuantity(), $done->toQuantity(), $qty->toQuantity(),
                )]);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, int|null>  $taskIds
     */
    private function recomputeTasks(Collection $taskIds): void
    {
        $ids = $taskIds->filter()->unique()->sort()->values();
        ProjectTask::query()->whereKey($ids)->orderBy('id')->get()->each(fn (ProjectTask $t) => $this->taskProgress->recompute($t));
    }

    /**
     * @return Builder<BoqItem>
     */
    private function currentLines(Project $project): Builder
    {
        return BoqItem::query()->whereHas('boq', fn (Builder $q) => $q
            ->where('project_id', $project->id)
            ->where('is_current', true)
            ->where('status', BoqStatus::Approved));
    }

    private function currentLine(Project $project, string $uid): ?BoqItem
    {
        return $this->currentLines($project)->where('line_uid', $uid)->first();
    }
}
