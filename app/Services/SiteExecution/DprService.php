<?php

namespace App\Services\SiteExecution;

use App\Enums\SiteExecution\DprStatus;
use App\Models\Planning\ProgressEntry;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprEquipment;
use App\Models\SiteExecution\DprItem;
use App\Models\SiteExecution\DprLabour;
use App\Models\SiteExecution\DprMaterial;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Planning\ProgressLedgerService;
use App\Services\SiteExecution\Concerns\ResolvesWorkLines;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * DPRs: one per project per day, created from that day's approved site diaries and editable until
 * submitted. Final approval (approval engine) posts progress through ProgressLedgerService and
 * locks the DPR. An approved DPR is corrected by reopening it: its postings are reversed, the
 * revision increases and it returns to draft for editing and re-approval.
 */
class DprService
{
    use ResolvesWorkLines;

    public const MAX_LINES = 200;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly DprAggregationService $aggregation,
        private readonly ProgressLedgerService $ledger,
    ) {}

    /**
     * @param  array{dpr_date: string, engineer_id?: ?int, remarks?: ?string}  $data
     */
    public function create(Project $project, array $data): Dpr
    {
        $date = CarbonImmutable::parse($data['dpr_date'])->toDateString();

        return DB::transaction(function () use ($project, $data, $date) {
            $existing = Dpr::query()->withTrashed()->where('project_id', $project->id)->whereDate('dpr_date', $date)->lockForUpdate()->first();
            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages(['dpr_date' => "{$existing->dpr_number} already covers this date."]);
            }

            $content = $this->aggregation->aggregate($project, $date);
            if ($content['diary_ids'] === []) {
                throw ValidationException::withMessages(['dpr_date' => 'There is no approved site diary for this date. Approve the diaries first.']);
            }

            if ($existing !== null) {
                // The unique (project, date) index also covers deleted DPRs: reuse the number and row.
                $existing->restore();
                $dpr = $existing;
            } else {
                $dpr = new Dpr;
                $dpr->forceFill([
                    'project_id' => $project->id,
                    'dpr_date' => $date,
                    'dpr_number' => $this->numbers->next('dpr', $project, CarbonImmutable::parse($date)),
                ]);
            }

            $dpr->fill([
                'engineer_id' => $this->engineer($project, $data['engineer_id'] ?? null),
                'weather' => $content['weather'],
                'site_issues' => $content['site_issues'],
                'remarks' => $data['remarks'] ?? null,
            ]);
            $dpr->forceFill(['status' => DprStatus::Draft])->save();

            $this->replaceContent($dpr, $project, $content);

            return $dpr;
        });
    }

    /**
     * @param  array<string, mixed>  $data  header + items (with optional id), labours, equipment, materials
     */
    public function update(Dpr $dpr, array $data): Dpr
    {
        return DB::transaction(function () use ($dpr, $data) {
            $locked = $this->lockEditable($dpr);
            $project = Project::query()->findOrFail($locked->project_id);

            $locked->fill([
                'engineer_id' => $this->engineer($project, $data['engineer_id'] ?? null),
                'weather' => $data['weather'] ?? null,
                'site_issues' => $data['site_issues'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $this->replaceContent($locked, $project, $data);
            $dpr->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Re-read the approved diaries of the DPR date (e.g. a diary approved after the DPR was made).
     * Lines are matched by task + BOQ line + unit so posted line history is kept.
     */
    public function refresh(Dpr $dpr): Dpr
    {
        return DB::transaction(function () use ($dpr) {
            $locked = $this->lockEditable($dpr);
            $project = Project::query()->findOrFail($locked->project_id);
            $content = $this->aggregation->aggregate($project, $locked->dpr_date->toDateString());

            $existing = DprItem::query()->where('dpr_id', $locked->id)->get()->keyBy(fn (DprItem $i) => $this->itemKey($i->task_id, $i->boq_item_id, $i->unit_id, $i->description));
            $content['items'] = array_map(function (array $row) use ($existing) {
                $match = $existing->get($this->itemKey($row['task_id'], $row['boq_item_id'], $row['unit_id'], $row['description']));

                return $row + ['id' => $match?->id];
            }, $content['items']);

            $locked->fill(['weather' => $content['weather'], 'site_issues' => $content['site_issues']])->save();
            $this->replaceContent($locked, $project, $content);
            $dpr->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function delete(Dpr $dpr): void
    {
        DB::transaction(function () use ($dpr) {
            $locked = $this->lockEditable($dpr);
            if ($locked->revision > 0 || $this->hasLedgerHistory($locked)) {
                throw ValidationException::withMessages(['dpr' => 'This DPR has progress history; correct it instead of deleting it.']);
            }
            $locked->delete();
        });
    }

    public function submit(Dpr $dpr, User $user): void
    {
        DB::transaction(function () use ($dpr, $user) {
            $locked = $this->lockEditable($dpr);
            $this->ledger->snapshot($locked);
            $this->ledger->assertPostable($locked);

            $this->approvals->submit($locked, $user);
            $dpr->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the approval engine's transaction): posts progress and locks the DPR.
     * The approver needs dpr.approve; over-progress aborts the whole approval. Idempotent.
     */
    public function post(Dpr $dpr, ?int $approverId): void
    {
        DB::transaction(function () use ($dpr, $approverId) {
            $locked = Dpr::query()->whereKey($dpr->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DprStatus::Approved) {
                return;
            }
            if ($locked->status !== DprStatus::Submitted) {
                throw ValidationException::withMessages(['dpr' => 'Only a submitted DPR can be approved.']);
            }

            $approver = $approverId ? User::query()->find($approverId) : null;
            if ($approver === null || ! $this->mayApprove($approver, (int) $locked->company_id)) {
                throw ValidationException::withMessages(['approval' => 'Approving a DPR needs the dpr.approve permission.']);
            }

            $this->ledger->postDpr($locked, $approverId);

            $locked->forceFill([
                'status' => DprStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
            $dpr->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Correction: reverse the DPR's progress entries and return it to draft (revision + 1).
     */
    public function reopen(Dpr $dpr, User $user, string $reason): void
    {
        DB::transaction(function () use ($dpr, $user, $reason) {
            $locked = Dpr::query()->whereKey($dpr->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DprStatus::Approved) {
                throw ValidationException::withMessages(['dpr' => 'Only an approved DPR can be reopened for correction.']);
            }

            $this->ledger->reverseDpr($locked, $user->id);

            $locked->forceFill([
                'status' => DprStatus::Draft,
                'revision' => $locked->revision + 1,
                'approved_by' => null,
                'approved_at' => null,
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ])->save();

            $this->ledger->snapshot($locked);
            $dpr->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function hasLedgerHistory(Dpr $dpr): bool
    {
        return ProgressEntry::query()
            ->where('source_type', (new DprItem)->getMorphClass())
            ->whereIn('source_id', DprItem::query()->where('dpr_id', $dpr->id)->select('id'))
            ->exists();
    }

    /**
     * Permission check in the DPR's company, also outside an HTTP request (queued / console).
     */
    private function mayApprove(User $user, int $companyId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->checkPermissionTo('dpr.approve');
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function lockEditable(Dpr $dpr): Dpr
    {
        $locked = Dpr::query()->whereKey($dpr->id)->lockForUpdate()->firstOrFail();
        $locked->assertEditable();

        return $locked;
    }

    private function engineer(Project $project, mixed $userId): ?int
    {
        if (blank($userId)) {
            return null;
        }

        $member = $project->users()->wherePivot('is_active', true)->where('users.id', (int) $userId)->exists();

        return $member ? (int) $userId : throw ValidationException::withMessages(['engineer_id' => 'Choose an active member of the project team.']);
    }

    private function itemKey(?int $taskId, ?int $boqItemId, ?int $unitId, ?string $description): string
    {
        return $taskId || $boqItemId
            ? "t{$taskId}|b{$boqItemId}|u{$unitId}"
            : 'd'.mb_strtolower(trim((string) $description)).'|u'.$unitId;
    }

    /**
     * Work lines are synced by id: posted lines are never deleted (their quantity may go to 0);
     * labour, equipment and material lines are replaced.
     *
     * @param  array<string, mixed>  $content
     */
    private function replaceContent(Dpr $dpr, Project $project, array $content): void
    {
        $items = array_values($content['items'] ?? []);
        foreach (['items', 'labours', 'equipment', 'materials'] as $key) {
            if (count($content[$key] ?? []) > self::MAX_LINES) {
                throw ValidationException::withMessages([$key => 'At most '.self::MAX_LINES.' lines.']);
            }
        }

        $existing = DprItem::query()->where('dpr_id', $dpr->id)->get()->keyBy('id');
        $keep = [];

        foreach ($items as $i => $row) {
            $target = $this->workTarget($project, $row, "items.{$i}");
            $item = ! blank($row['id'] ?? null) ? $existing->get((int) $row['id']) : null;
            $item ??= (new DprItem)->forceFill(['dpr_id' => $dpr->id]);

            $item->forceFill([
                'task_id' => $target['task']?->id,
                'boq_item_id' => $target['boq']?->id,
                'description' => $row['description'] ?? null,
                'unit_id' => $target['unit']->id,
                'executed_qty' => $this->quantity($row['executed_qty'] ?? null, "items.{$i}.executed_qty", allowZero: true)->toQuantity(),
                'sort_order' => $i + 1,
            ])->save();
            $keep[] = $item->id;
        }

        $removed = $existing->except($keep);
        if ($removed->isNotEmpty()) {
            $history = ProgressEntry::query()->where('source_type', (new DprItem)->getMorphClass())->whereIn('source_id', $removed->modelKeys())->exists();
            if ($history) {
                throw ValidationException::withMessages(['items' => 'A line that was posted before cannot be removed; set its quantity to 0 instead.']);
            }
            $removed->each->delete();
        }

        DprLabour::query()->where('dpr_id', $dpr->id)->get()->each->delete();
        DprEquipment::query()->where('dpr_id', $dpr->id)->get()->each->delete();
        DprMaterial::query()->where('dpr_id', $dpr->id)->get()->each->delete();

        foreach (array_values($content['labours'] ?? []) as $i => $row) {
            (new DprLabour)->forceFill([
                'dpr_id' => $dpr->id,
                'labour_trade_id' => $this->labourTrade($row['labour_trade_id'] ?? null, "labours.{$i}.labour_trade_id")->id,
                'subcontractor_id' => $this->subcontractor($row['subcontractor_id'] ?? null, "labours.{$i}.subcontractor_id")?->id,
                'headcount' => $this->headcount($row['headcount'] ?? null, "labours.{$i}.headcount"),
                'hours' => $this->hours($row['hours'] ?? null, "labours.{$i}.hours"),
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }

        foreach (array_values($content['equipment'] ?? []) as $i => $row) {
            $type = $this->equipmentType($row['equipment_type_id'] ?? null, "equipment.{$i}.equipment_type_id");
            if ($type === null && blank($row['description'] ?? null)) {
                throw ValidationException::withMessages(["equipment.{$i}.equipment_type_id" => 'Choose the equipment type or describe the equipment.']);
            }
            (new DprEquipment)->forceFill([
                'dpr_id' => $dpr->id,
                'equipment_type_id' => $type?->id,
                'description' => $row['description'] ?? null,
                'working_hours' => $this->hours($row['working_hours'] ?? null, "equipment.{$i}.working_hours"),
                'idle_hours' => $this->hours($row['idle_hours'] ?? null, "equipment.{$i}.idle_hours"),
            ])->save();
        }

        foreach (array_values($content['materials'] ?? []) as $i => $row) {
            $resolved = $this->materialWithUnit($row['material_id'] ?? null, $row['unit_id'] ?? null, "materials.{$i}");
            (new DprMaterial)->forceFill([
                'dpr_id' => $dpr->id,
                'material_id' => $resolved['material']->id,
                'quantity' => $this->quantity($row['quantity'] ?? null, "materials.{$i}.quantity")->toQuantity(),
                'unit_id' => $resolved['unit_id'],
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }

        $this->ledger->snapshot($dpr);
    }
}
