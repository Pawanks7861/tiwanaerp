<?php

namespace App\Services\Finance;

use App\Enums\CostHead;
use App\Models\Finance\ProjectCostEntry;
use App\Support\Math\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writer of the append-only project cost ledger: material issues and site returns (Phase 4),
 * approved labour attendance, certified subcontractor bills and posted equipment usage (Phase 6).
 */
class ProjectCostLedgerService
{
    /**
     * Idempotent per source line and cost head: while a posting of the source is in force a retry
     * returns it. Once every earlier posting has been reversed, the source can be posted again
     * (posting_ref r1, r2, ...), which is how corrected documents are re-posted.
     */
    public function post(
        Model $source,
        int $projectId,
        CostHead $head,
        Decimal $amount,
        CarbonInterface|string $date,
        ?int $boqItemId = null,
        ?string $boqLineUid = null,
        ?int $taskId = null,
        ?string $remarks = null,
        ?int $userId = null,
    ): ProjectCostEntry {
        return DB::transaction(function () use ($source, $projectId, $head, $amount, $date, $boqItemId, $boqLineUid, $taskId, $remarks, $userId) {
            $forward = ProjectCostEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('cost_head', $head)
                ->where('is_reversal', false)
                ->lockForUpdate()
                ->get();

            $reversed = ProjectCostEntry::query()->whereIn('reverses_id', $forward->modelKeys())->pluck('reverses_id')->all();
            if ($active = $forward->first(fn (ProjectCostEntry $e) => ! in_array($e->id, $reversed, true))) {
                return $active;
            }

            return $this->insert([
                'project_id' => $projectId,
                'cost_head' => $head,
                'boq_item_id' => $boqItemId,
                'boq_line_uid' => $boqLineUid,
                'task_id' => $taskId,
                'entry_date' => $date,
                'amount' => $amount->toMoney(),
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'posting_ref' => $forward->isEmpty() ? '' : 'r'.$forward->count(),
                'is_reversal' => false,
                'remarks' => $remarks,
                'created_by' => $userId ?? Auth::id(),
            ]);
        });
    }

    public function reverse(ProjectCostEntry $entry, ?string $remarks = null, ?int $userId = null): ProjectCostEntry
    {
        if ($entry->is_reversal) {
            throw new LogicException('A reversal cannot itself be reversed.');
        }

        return DB::transaction(function () use ($entry, $remarks, $userId) {
            if ($existing = ProjectCostEntry::query()->where('reverses_id', $entry->id)->lockForUpdate()->first()) {
                return $existing;
            }

            return $this->insert([
                'project_id' => $entry->project_id,
                'cost_head' => $entry->cost_head,
                'boq_item_id' => $entry->boq_item_id,
                'boq_line_uid' => $entry->boq_line_uid,
                'task_id' => $entry->task_id,
                'entry_date' => now()->toDateString(),
                'amount' => Decimal::of($entry->amount)->negate()->toMoney(),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'posting_ref' => $entry->posting_ref ?? '',
                'reverses_id' => $entry->id,
                'is_reversal' => true,
                'remarks' => $remarks,
                'created_by' => $userId ?? Auth::id(),
            ]);
        });
    }

    /**
     * Forward (non-reversal) entry of a source line, if posted.
     */
    public function entryFor(Model $source, CostHead $head): ?ProjectCostEntry
    {
        return ProjectCostEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('cost_head', $head)
            ->where('is_reversal', false)
            ->first();
    }

    /**
     * The forward entry currently in force (posted and not reversed), if any.
     */
    public function activeEntryFor(Model $source, CostHead $head): ?ProjectCostEntry
    {
        return ProjectCostEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('cost_head', $head)
            ->where('is_reversal', false)
            ->whereNotExists(fn ($q) => $q->from('project_cost_ledger as rev')->whereColumn('rev.reverses_id', 'project_cost_ledger.id'))
            ->latest('id')
            ->first();
    }

    /**
     * Reverse the entry in force for a source (no-op when nothing is posted).
     */
    public function reverseActive(Model $source, CostHead $head, ?string $remarks = null, ?int $userId = null): ?ProjectCostEntry
    {
        $entry = $this->activeEntryFor($source, $head);

        return $entry ? $this->reverse($entry, $remarks, $userId) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): ProjectCostEntry
    {
        $entry = new ProjectCostEntry;
        $entry->forceFill($attributes)->save();

        return $entry;
    }
}
