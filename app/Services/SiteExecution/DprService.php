<?php

namespace App\Services\SiteExecution;

use App\Enums\SiteExecution\DprStatus;
use App\Models\Approval\ApprovalAction;
use App\Models\Approval\ApprovalRequest;
use App\Models\Core\Attachment;
use App\Models\Planning\ProgressEntry;
use App\Models\Projects\Project;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprConstraint;
use App\Models\SiteExecution\DprEquipment;
use App\Models\SiteExecution\DprItem;
use App\Models\SiteExecution\DprLabour;
use App\Models\SiteExecution\DprMaterial;
use App\Support\Math\Decimal;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Files\FilePreviewService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Planning\ProgressLedgerService;
use App\Services\SiteExecution\Concerns\ResolvesWorkLines;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * DPRs: one per project per day. Created directly from Planning/WBS (and optional attendance /
 * equipment / inventory summaries). Site Diary is legacy and is NOT required.
 * Final approval posts progress through ProgressLedgerService and locks the DPR.
 */
class DprService
{
    use ResolvesWorkLines;

    public const MAX_LINES = 200;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly DprPrefillService $prefill,
        private readonly ProgressLedgerService $ledger,
    ) {}

    /**
     * @param  array{dpr_date: string, engineer_id?: ?int, remarks?: ?string, site_id?: ?int, include_completed?: bool}  $data
     */
    public function create(Project $project, array $data): Dpr
    {
        $date = CarbonImmutable::parse($data['dpr_date'])->toDateString();
        $includeCompleted = (bool) ($data['include_completed'] ?? false);

        return DB::transaction(function () use ($project, $data, $date, $includeCompleted) {
            $existing = Dpr::query()->withTrashed()->where('project_id', $project->id)->whereDate('dpr_date', $date)->lockForUpdate()->first();
            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages(['dpr_date' => "{$existing->dpr_number} already covers this date."]);
            }

            // Prefer direct prefill. Optionally merge legacy approved diaries when present (historical days).
            $content = $this->prefill->forDateMergingLegacy($project, $date, $includeCompleted);

            if ($existing !== null) {
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
                'weather' => $data['weather'] ?? $content['weather'],
                'site_issues' => $data['site_issues'] ?? $content['site_issues'],
                'remarks' => $data['remarks'] ?? null,
                'work_performed' => $data['work_performed'] ?? null,
                'constraints_summary' => $data['constraints_summary'] ?? null,
                'department' => $data['department'] ?? null,
                'agency_name' => $data['agency_name'] ?? null,
                'site_id' => $data['site_id'] ?? null,
            ]);
            $dpr->forceFill([
                'status' => DprStatus::Draft,
                'source' => $content['source'] ?? 'direct',
            ])->save();

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
                'work_performed' => $data['work_performed'] ?? $locked->work_performed,
                'constraints_summary' => $data['constraints_summary'] ?? $locked->constraints_summary,
                'department' => $data['department'] ?? $locked->department,
                'agency_name' => $data['agency_name'] ?? $locked->agency_name,
                'site_id' => array_key_exists('site_id', $data) ? $data['site_id'] : $locked->site_id,
            ])->save();

            $this->replaceContent($locked, $project, $data);
            $dpr->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Re-prefill activity/manpower/equipment/material summaries for the DPR date.
     * Preserves matching item ids (and executed quantities already entered) where possible.
     */
    public function refresh(Dpr $dpr, bool $includeCompleted = false): Dpr
    {
        return DB::transaction(function () use ($dpr, $includeCompleted) {
            $locked = $this->lockEditable($dpr);
            $project = Project::query()->findOrFail($locked->project_id);
            $date = $locked->dpr_date->toDateString();
            $content = $this->prefill->forDate($project, $date, $includeCompleted);

            $existing = DprItem::query()->where('dpr_id', $locked->id)->get()->keyBy(
                fn (DprItem $i) => $this->itemKey($i->task_id, $i->boq_item_id, $i->unit_id, $i->description)
            );
            $content['items'] = array_map(function (array $row) use ($existing) {
                $match = $existing->get($this->itemKey($row['task_id'] ?? null, $row['boq_item_id'] ?? null, $row['unit_id'] ?? null, $row['description'] ?? null));
                if ($match) {
                    $row['id'] = $match->id;
                    $row['executed_qty'] = (string) $match->executed_qty;
                    $row['today_target_qty'] = $match->today_target_qty !== null ? (string) $match->today_target_qty : ($row['today_target_qty'] ?? null);
                    $row['hold_quantity'] = (string) ($match->hold_quantity ?? '0');
                    $row['hold_reason'] = $match->hold_reason;
                    $row['shortfall_reason'] = $match->shortfall_reason;
                    $row['remarks'] = $match->remarks;
                }

                return $row;
            }, $content['items']);

            // Keep user-entered constraints/labours overrides unless empty.
            if ($locked->labours()->exists() && ($content['labours'] ?? []) === []) {
                unset($content['labours']);
            }
            if ($locked->equipment()->exists() && ($content['equipment'] ?? []) === []) {
                unset($content['equipment']);
            }

            $this->replaceContent($locked, $project, $content);
            $dpr->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Permanently remove an editable DPR and all related rows/files (items, resources,
     * constraints, attachments, approval history, stored PDF). Blocked once progress
     * has been posted or the DPR has been reopened (revision > 0).
     */
    public function delete(Dpr $dpr): void
    {
        DB::transaction(function () use ($dpr) {
            $locked = $this->lockEditable($dpr);
            if ($locked->revision > 0 || $this->hasLedgerHistory($locked)) {
                throw ValidationException::withMessages(['dpr' => 'This DPR has progress history; correct it instead of deleting it.']);
            }

            $this->purgeRelated($locked);
            $locked->forceDelete();
        });
    }

    /**
     * Hard-delete every child row and related morph / file for a DPR. Call only inside
     * a transaction on an editable DPR with no ledger history.
     */
    private function purgeRelated(Dpr $dpr): void
    {
        $requestIds = ApprovalRequest::query()
            ->where('approvable_type', $dpr->getMorphClass())
            ->where('approvable_id', $dpr->id)
            ->pluck('id');

        if ($requestIds->isNotEmpty()) {
            ApprovalAction::query()->whereIn('approval_request_id', $requestIds)->delete();
            ApprovalRequest::query()->whereIn('id', $requestIds)->delete();
        }

        $attachments = Attachment::withTrashed()
            ->where('attachable_type', $dpr->getMorphClass())
            ->where('attachable_id', $dpr->id)
            ->get();

        $previews = app(FilePreviewService::class);
        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $previews->purge('attachment', (int) $attachment->id);
            $attachment->forceDelete();
        }

        if (filled($dpr->pdf_path)) {
            Storage::disk(config('uploads.disk'))->delete($dpr->pdf_path);
        }

        DprItem::query()->where('dpr_id', $dpr->id)->delete();
        DprLabour::query()->where('dpr_id', $dpr->id)->delete();
        DprEquipment::query()->where('dpr_id', $dpr->id)->delete();
        DprMaterial::query()->where('dpr_id', $dpr->id)->delete();
        DprConstraint::query()->where('dpr_id', $dpr->id)->delete();
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

            $hold = $this->quantity($row['hold_quantity'] ?? '0', "items.{$i}.hold_quantity", allowZero: true);
            $targetQty = isset($row['today_target_qty']) && $row['today_target_qty'] !== '' && $row['today_target_qty'] !== null
                ? $this->quantity($row['today_target_qty'], "items.{$i}.today_target_qty", allowZero: true)
                : null;
            $executed = $this->quantity($row['executed_qty'] ?? null, "items.{$i}.executed_qty", allowZero: true);

            $item->forceFill([
                'task_id' => $target['task']?->id,
                'boq_item_id' => $target['boq']?->id,
                'description' => $row['description'] ?? null,
                'activity_name_snapshot' => $row['activity_name_snapshot'] ?? $row['description'] ?? $target['task']?->name,
                'work_area' => $row['work_area'] ?? null,
                'unit_snapshot' => $row['unit_snapshot'] ?? $target['unit']->symbol ?? $target['unit']->name,
                'unit_id' => $target['unit']->id,
                'planned_start_date' => $row['planned_start_date'] ?? null,
                'planned_end_date' => $row['planned_end_date'] ?? null,
                'today_target_qty' => $targetQty?->toQuantity(),
                'executed_qty' => $executed->toQuantity(),
                'hold_quantity' => $hold->toQuantity(),
                'hold_reason' => $row['hold_reason'] ?? null,
                'hold_since' => $row['hold_since'] ?? null,
                'hold_responsible' => $row['hold_responsible'] ?? null,
                'hold_expected_resolution' => $row['hold_expected_resolution'] ?? null,
                'hold_status' => $row['hold_status'] ?? null,
                'on_hold' => (bool) ($row['on_hold'] ?? $hold->isPositive()),
                'shortfall_reason' => $row['shortfall_reason'] ?? null,
                'remarks' => $row['remarks'] ?? null,
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

        if (array_key_exists('labours', $content)) {
            DprLabour::query()->where('dpr_id', $dpr->id)->get()->each->delete();
            foreach (array_values($content['labours'] ?? []) as $i => $row) {
                (new DprLabour)->forceFill([
                    'dpr_id' => $dpr->id,
                    'labour_trade_id' => $this->labourTrade($row['labour_trade_id'] ?? null, "labours.{$i}.labour_trade_id")->id,
                    'subcontractor_id' => $this->subcontractor($row['subcontractor_id'] ?? null, "labours.{$i}.subcontractor_id")?->id,
                    'headcount' => $this->headcount($row['headcount'] ?? null, "labours.{$i}.headcount"),
                    'skilled' => isset($row['skilled']) ? (int) $row['skilled'] : null,
                    'unskilled' => isset($row['unskilled']) ? (int) $row['unskilled'] : null,
                    'operator_count' => isset($row['operator_count']) ? (int) $row['operator_count'] : null,
                    'helper_count' => isset($row['helper_count']) ? (int) $row['helper_count'] : null,
                    'hours' => $this->hours($row['hours'] ?? null, "labours.{$i}.hours"),
                    'remarks' => $row['remarks'] ?? null,
                    'is_informational' => (bool) ($row['is_informational'] ?? true),
                    'source' => $row['source'] ?? 'manual',
                ])->save();
            }
        }

        if (array_key_exists('equipment', $content)) {
            DprEquipment::query()->where('dpr_id', $dpr->id)->get()->each->delete();
            foreach (array_values($content['equipment'] ?? []) as $i => $row) {
                $type = $this->equipmentType($row['equipment_type_id'] ?? null, "equipment.{$i}.equipment_type_id");
                if ($type === null && blank($row['description'] ?? null) && blank($row['equipment_id'] ?? null)) {
                    throw ValidationException::withMessages(["equipment.{$i}.equipment_type_id" => 'Choose the equipment type or describe the equipment.']);
                }
                $working = $this->hours($row['working_hours'] ?? null, "equipment.{$i}.working_hours");
                $idle = $this->hours($row['idle_hours'] ?? null, "equipment.{$i}.idle_hours");
                (new DprEquipment)->forceFill([
                    'dpr_id' => $dpr->id,
                    'equipment_id' => $row['equipment_id'] ?? null,
                    'equipment_type_id' => $type?->id,
                    'description' => $row['description'] ?? null,
                    'operator_name' => $row['operator_name'] ?? null,
                    'start_time' => $row['start_time'] ?? null,
                    'end_time' => $row['end_time'] ?? null,
                    'duration_hours' => $row['duration_hours'] ?? Decimal::of($working)->plus($idle)->round(2)->toString(),
                    'working_hours' => $working,
                    'idle_hours' => $idle,
                    'status' => $row['status'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                    'is_informational' => (bool) ($row['is_informational'] ?? true),
                ])->save();
            }
        }

        if (array_key_exists('materials', $content)) {
            DprMaterial::query()->where('dpr_id', $dpr->id)->get()->each->delete();
            foreach (array_values($content['materials'] ?? []) as $i => $row) {
                $resolved = $this->materialWithUnit($row['material_id'] ?? null, $row['unit_id'] ?? null, "materials.{$i}");
                $issued = $this->quantity($row['issued_qty'] ?? $row['quantity'] ?? '0.0001', "materials.{$i}.quantity", allowZero: true);
                $qty = $issued->isPositive() ? $issued : Decimal::of('0.0001');
                (new DprMaterial)->forceFill([
                    'dpr_id' => $dpr->id,
                    'material_id' => $resolved['material']->id,
                    'opening_qty' => isset($row['opening_qty']) ? $this->quantity($row['opening_qty'], "materials.{$i}.opening_qty", allowZero: true)->toQuantity() : null,
                    'received_qty' => isset($row['received_qty']) ? $this->quantity($row['received_qty'], "materials.{$i}.received_qty", allowZero: true)->toQuantity() : null,
                    'issued_qty' => $issued->toQuantity(),
                    'closing_qty' => isset($row['closing_qty']) ? $this->quantity($row['closing_qty'], "materials.{$i}.closing_qty", allowZero: true)->toQuantity() : null,
                    'quantity' => $qty->toQuantity(),
                    'unit_id' => $resolved['unit_id'],
                    'remarks' => $row['remarks'] ?? null,
                    'is_informational' => (bool) ($row['is_informational'] ?? true),
                    'source' => $row['source'] ?? 'manual',
                ])->save();
            }
        }

        if (array_key_exists('constraints', $content)) {
            DprConstraint::query()->where('dpr_id', $dpr->id)->delete();
            foreach (array_values($content['constraints'] ?? []) as $i => $row) {
                if (blank($row['category'] ?? null) || blank($row['description'] ?? null)) {
                    continue;
                }
                (new DprConstraint)->forceFill([
                    'dpr_id' => $dpr->id,
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'work_area' => $row['work_area'] ?? null,
                    'task_id' => $row['task_id'] ?? null,
                    'responsible' => $row['responsible'] ?? null,
                    'priority' => $row['priority'] ?? null,
                    'raised_date' => $row['raised_date'] ?? null,
                    'expected_resolution' => $row['expected_resolution'] ?? null,
                    'status' => $row['status'] ?? 'open',
                    'remarks' => $row['remarks'] ?? null,
                    'sort_order' => $i + 1,
                ])->save();
            }
        }

        $this->ledger->snapshot($dpr);
    }

}
