<?php

namespace App\Services\Quality;

use App\Enums\Quality\CheckpointResult;
use App\Enums\Quality\InspectionResult;
use App\Enums\Quality\InspectionStatus;
use App\Models\Projects\Project;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityInspection;
use App\Models\Quality\QualityInspectionItem;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inspections: requested → scheduled → completed.
 *
 * Creating copies the checklist checkpoints into quality_inspection_items. Checkpoint results
 * (pass / fail / N/A) are recorded while scheduled. Completion rule (server side):
 *  - every checkpoint must be assessed, and at least one must be pass or fail (not all N/A);
 *  - any failed checkpoint makes the inspection failed (a passed / conditional request is refused);
 *  - with no failed checkpoint the inspector chooses passed (default) or conditional; failed is
 *    refused; conditional requires remarks.
 * The inspection is then locked. Nothing here touches stock, cost, progress or billing.
 */
class QualityInspectionService
{
    use ResolvesProjectRefs;

    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data, User $user): QualityInspection
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $checklist = is_numeric($data['quality_checklist_id'] ?? null)
                ? QualityChecklist::query()->active()->whereKey((int) $data['quality_checklist_id'])->first()
                : null;
            if ($checklist === null) {
                throw ValidationException::withMessages(['quality_checklist_id' => 'Choose an active checklist.']);
            }
            $templateItems = $checklist->items()->get();
            if ($templateItems->isEmpty()) {
                throw ValidationException::withMessages(['quality_checklist_id' => 'This checklist has no checkpoints.']);
            }

            $inspection = new QualityInspection;
            $inspection->forceFill([
                'project_id' => $project->id,
                'inspection_number' => $this->numbers->next('quality_inspection', $project),
                'quality_checklist_id' => $checklist->id,
                'status' => InspectionStatus::Requested,
                'requested_by' => $user->id,
                ...$this->details($project, $data),
            ])->save();

            foreach ($templateItems as $template) {
                $item = new QualityInspectionItem;
                $item->forceFill([
                    'quality_inspection_id' => $inspection->id,
                    'quality_checklist_item_id' => $template->id,
                    'sort_order' => $template->sort_order,
                    'checkpoint' => $template->checkpoint,
                    'acceptance_criteria' => $template->acceptance_criteria,
                ])->save();
            }

            return $inspection;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QualityInspection $inspection, array $data): QualityInspection
    {
        return DB::transaction(function () use ($inspection, $data) {
            $locked = $this->lock($inspection);
            $locked->assertEditable();

            $details = $this->details($locked->project, $data);
            if ($locked->status === InspectionStatus::Scheduled && $details['inspection_date'] === null) {
                throw ValidationException::withMessages(['inspection_date' => 'A scheduled inspection needs a date.']);
            }
            $locked->forceFill($details)->save();

            return $locked;
        });
    }

    public function delete(QualityInspection $inspection): void
    {
        DB::transaction(function () use ($inspection) {
            $locked = $this->lock($inspection);
            if ($locked->status !== InspectionStatus::Requested) {
                throw ValidationException::withMessages(['inspection' => 'Only a requested inspection can be deleted.']);
            }
            $locked->delete();
        });
    }

    /**
     * @param  array{inspection_date: string, engineer_id?: ?int}  $data
     */
    public function schedule(QualityInspection $inspection, array $data): void
    {
        DB::transaction(function () use ($inspection, $data) {
            $locked = $this->lock($inspection);
            if ($locked->status !== InspectionStatus::Requested) {
                throw ValidationException::withMessages(['inspection' => 'Only a requested inspection can be scheduled.']);
            }

            $engineerId = $this->projectMember($locked->project, $data['engineer_id'] ?? $locked->engineer_id, 'engineer_id');
            $locked->forceFill([
                'status' => InspectionStatus::Scheduled,
                'inspection_date' => $data['inspection_date'],
                'engineer_id' => $engineerId,
            ])->save();
            $locked->writeAudit('scheduled', null, ['inspection_date' => $data['inspection_date'], 'engineer_id' => $engineerId]);
            $inspection->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Save checkpoint results without completing (partial is fine).
     *
     * @param  array<int|string, array{result?: ?string, remark?: ?string}>  $results  keyed by inspection item id
     */
    public function record(QualityInspection $inspection, array $results): void
    {
        DB::transaction(function () use ($inspection, $results) {
            $locked = $this->lock($inspection);
            $this->assertScheduled($locked);
            $this->applyResults($locked, $results);
        });
    }

    /**
     * @param  array{result?: ?string, remarks?: ?string, items?: array<int|string, array{result?: ?string, remark?: ?string}>}  $data
     */
    public function complete(QualityInspection $inspection, array $data, User $user): void
    {
        DB::transaction(function () use ($inspection, $data, $user) {
            $locked = $this->lock($inspection);
            $this->assertScheduled($locked);
            if (isset($data['items'])) {
                $this->applyResults($locked, $data['items']);
            }

            $items = $locked->items()->get();
            $remarks = filled($data['remarks'] ?? null) ? trim($data['remarks']) : null;
            $result = $this->decideResult($items, $data['result'] ?? null, $remarks);

            $locked->forceFill([
                'status' => InspectionStatus::Completed,
                'result' => $result,
                'remarks' => $remarks,
                'inspection_date' => $locked->inspection_date ?? now()->toDateString(),
                'completed_by' => $user->id,
                'completed_at' => now(),
            ])->save();

            $counts = $items->countBy(fn (QualityInspectionItem $i) => $i->result->value);
            $locked->writeAudit('completed', null, [
                'result' => $result->value,
                'pass' => $counts->get('pass', 0),
                'fail' => $counts->get('fail', 0),
                'na' => $counts->get('na', 0),
            ]);
            $inspection->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  Collection<int, QualityInspectionItem>  $items
     */
    public function decideResult(Collection $items, ?string $requested, ?string $remarks): InspectionResult
    {
        $pending = $items->filter(fn (QualityInspectionItem $i) => $i->result === null)->count();
        if ($items->isEmpty() || $pending > 0) {
            throw ValidationException::withMessages(['items' => "Assess every checkpoint before completing ({$pending} not assessed)."]);
        }
        if ($items->every(fn (QualityInspectionItem $i) => $i->result === CheckpointResult::NotApplicable)) {
            throw ValidationException::withMessages(['items' => 'At least one checkpoint must be passed or failed; all are N/A.']);
        }

        $requestedResult = filled($requested) ? InspectionResult::tryFrom((string) $requested) : null;
        if (filled($requested) && $requestedResult === null) {
            throw ValidationException::withMessages(['result' => 'Choose passed, failed or conditional.']);
        }

        $hasFail = $items->contains(fn (QualityInspectionItem $i) => $i->result === CheckpointResult::Fail);
        if ($hasFail) {
            if ($requestedResult !== null && $requestedResult !== InspectionResult::Failed) {
                throw ValidationException::withMessages(['result' => 'A failed checkpoint makes the inspection failed.']);
            }

            return InspectionResult::Failed;
        }

        if ($requestedResult === InspectionResult::Failed) {
            throw ValidationException::withMessages(['result' => 'No checkpoint failed, so the inspection cannot be failed. Record the failing checkpoint first.']);
        }
        if ($requestedResult === InspectionResult::Conditional && $remarks === null) {
            throw ValidationException::withMessages(['remarks' => 'Explain the conditions of a conditional pass.']);
        }

        return $requestedResult ?? InspectionResult::Passed;
    }

    /**
     * @param  array<int|string, array{result?: ?string, remark?: ?string}>  $results
     */
    private function applyResults(QualityInspection $inspection, array $results): void
    {
        $items = $inspection->items()->get()->keyBy('id');

        foreach ($results as $itemId => $row) {
            $item = is_numeric($itemId) ? $items->get((int) $itemId) : null;
            if ($item === null) {
                throw ValidationException::withMessages(['items' => 'A checkpoint does not belong to this inspection.']);
            }

            $result = filled($row['result'] ?? null) ? CheckpointResult::tryFrom((string) $row['result']) : null;
            if (filled($row['result'] ?? null) && $result === null) {
                throw ValidationException::withMessages(["items.{$itemId}.result" => 'Choose pass, fail or N/A.']);
            }
            $remark = filled($row['remark'] ?? null) ? trim((string) $row['remark']) : null;
            if ($result === CheckpointResult::Fail && $remark === null) {
                throw ValidationException::withMessages(["items.{$itemId}.remark" => "Describe why \"{$item->checkpoint}\" failed."]);
            }

            $item->forceFill(['result' => $result, 'remark' => $remark])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(Project $project, array $data): array
    {
        $site = $this->site($project, $data['site_id'] ?? null);
        $task = $this->task($project, $data['task_id'] ?? null, 'task_id');
        $boqItem = $this->boqItem($project, $data['boq_item_id'] ?? null, 'boq_item_id');
        [$boqItemId, $lineUid] = $boqItem !== null ? [$boqItem->id, $boqItem->line_uid] : $this->boqRefOfTask($task?->id);

        return [
            'site_id' => $site?->id,
            'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
            'task_id' => $task?->id,
            'boq_item_id' => $boqItemId,
            'boq_line_uid' => $lineUid,
            'request_notes' => filled($data['request_notes'] ?? null) ? trim($data['request_notes']) : null,
            'inspection_date' => filled($data['inspection_date'] ?? null) ? $data['inspection_date'] : null,
            'engineer_id' => $this->projectMember($project, $data['engineer_id'] ?? null, 'engineer_id'),
        ];
    }

    /**
     * Active member of the project team who is also an active member of the company.
     */
    public function projectMember(Project $project, mixed $userId, string $key): ?int
    {
        if (blank($userId)) {
            return null;
        }

        $member = is_numeric($userId) && $project->users()->wherePivot('is_active', true)->where('users.id', (int) $userId)
            ->where('users.is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $project->company_id)->where('is_active', true))
            ->exists();

        return $member ? (int) $userId : throw ValidationException::withMessages([$key => 'Choose an active member of the project team.']);
    }

    private function assertScheduled(QualityInspection $inspection): void
    {
        if ($inspection->status !== InspectionStatus::Scheduled) {
            throw ValidationException::withMessages(['inspection' => $inspection->status === InspectionStatus::Completed
                ? 'A completed inspection cannot be changed.'
                : 'Schedule the inspection before recording results.']);
        }
    }

    private function lock(QualityInspection $inspection): QualityInspection
    {
        return QualityInspection::query()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
    }
}
