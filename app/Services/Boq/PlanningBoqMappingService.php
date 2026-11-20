<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Enums\Boq\PlanningTrackingBasis;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqPlanningMap;
use App\Models\Masters\Unit;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\ProjectWbsService;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual Planning ↔ BOQ allocation.
 * User chooses Nos or BOQ Quantity and enters a partial allocation; conversion is derived from BOQ.
 * Over-allocation is blocked (normalized to BOQ qty). Mapping may exist on approved BOQs.
 */
class PlanningBoqMappingService
{
    /**
     * @return array{
     *   maps: list<array<string, mixed>>,
     *   mapping_methods: list<array{value: string, label: string}>,
     *   tracking_bases: list<array{value: string, label: string}>,
     *   boq_item: array<string, mixed>,
     *   allocation: array<string, mixed>,
     *   locked: bool
     * }
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);
        $maps = BoqPlanningMap::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->whereHas('task')
            ->with(['task:id,name,wbs_code,planned_qty,unit_id,completed_qty', 'task.unit:id,symbol'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return [
            'maps' => $maps->map(fn (BoqPlanningMap $m) => $this->mapPayload($m))->all(),
            'mapping_methods' => PlanningBoqMappingMethod::options(),
            'tracking_bases' => PlanningTrackingBasis::options(),
            'boq_item' => $this->boqItemSnapshot($item),
            'allocation' => $this->allocationSummary($item),
            'locked' => false,
        ];
    }

    /**
     * Availability / mapped totals for a BOQ item (server-authoritative).
     *
     * @return array{
     *   boq_total_nos: ?string,
     *   boq_total_qty: string,
     *   boq_unit: ?string,
     *   conversion_factor: ?string,
     *   nos_mapping_available: bool,
     *   mapped_nos: string,
     *   mapped_boq_qty: string,
     *   available_nos: ?string,
     *   available_boq_qty: string,
     *   maps_count: int
     * }
     */
    public function allocationSummary(BoqItem $item, ?int $exceptMapId = null): array
    {
        $item->loadMissing('unit:id,symbol');
        $boqQty = Decimal::of($item->quantity ?? '0')->round(Decimal::QTY_SCALE);
        $boqNos = $this->boqNos($item);
        $factor = $this->conversionFactor($item);

        // Only maps on live (non soft-deleted) activities consume availability.
        $maps = $this->activeMapsOnLiveTasksQuery($item->id, $exceptMapId)->get();

        $mappedBoq = Decimal::zero();
        $mappedNos = Decimal::zero();
        foreach ($maps as $map) {
            $mappedBoq = $mappedBoq->plus($this->equivalentBoqQty($map, $item));
            if ($map->tracking_basis === PlanningTrackingBasis::Nos) {
                $mappedNos = $mappedNos->plus(Decimal::of($map->planning_qty ?? '0'));
            } elseif ($factor !== null && $factor->isPositive()) {
                $mappedNos = $mappedNos->plus(
                    Decimal::of($map->allocated_boq_qty ?? '0')->dividedBy($factor, Decimal::QTY_SCALE)
                );
            }
        }

        $availableBoq = $boqQty->minus($mappedBoq);
        if ($availableBoq->isNegative()) {
            $availableBoq = Decimal::zero();
        }

        $availableNos = null;
        if ($boqNos !== null) {
            $availableNos = $boqNos->minus($mappedNos);
            if ($availableNos->isNegative()) {
                $availableNos = Decimal::zero();
            }
            // Absorb conversion rounding dust once Nos or BOQ are fully consumed.
            if ($availableNos->isZero() && $availableBoq->isPositive()) {
                $availableBoq = Decimal::zero();
                $mappedBoq = $boqQty;
            } elseif ($availableBoq->isZero() && $availableNos->isPositive()) {
                $availableNos = Decimal::zero();
                $mappedNos = $boqNos;
            }
        }

        return [
            'boq_total_nos' => $boqNos?->toQuantity(),
            'boq_total_qty' => $boqQty->toQuantity(),
            'boq_unit' => $item->unit?->symbol,
            'conversion_factor' => $factor?->toQuantity(),
            'nos_mapping_available' => $boqNos !== null && $boqNos->isPositive(),
            'mapped_nos' => $mappedNos->round(Decimal::QTY_SCALE)->toQuantity(),
            'mapped_boq_qty' => $mappedBoq->round(Decimal::QTY_SCALE)->toQuantity(),
            'available_nos' => $availableNos?->round(Decimal::QTY_SCALE)->toQuantity(),
            'available_boq_qty' => $availableBoq->round(Decimal::QTY_SCALE)->toQuantity(),
            'maps_count' => $maps->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{payload: array<string, mixed>}
     */
    public function saveMaps(BoqItem $item, array $rows, User $user): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);

        $payload = DB::transaction(function () use ($item, $rows, $user) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->with(['unit:id,symbol', 'boq'])->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($lockedItem->boq_id)->lockForUpdate()->firstOrFail();

            $kept = [];
            $resolvedRows = [];
            $batchClaimedNos = Decimal::zero();
            $batchClaimedBoq = Decimal::zero();
            foreach (array_values($rows) as $i => $row) {
                $taskId = (int) ($row['project_task_id'] ?? 0);
                $task = ProjectTask::query()
                    ->whereKey($taskId)
                    ->where('project_id', $lockedBoq->project_id)
                    ->with('unit:id,symbol')
                    ->lockForUpdate()
                    ->first();
                if ($task === null) {
                    throw ValidationException::withMessages(["maps.$i.project_task_id" => 'Choose a planning activity on this project.']);
                }
                if ($task->isPlanningHead()) {
                    throw ValidationException::withMessages(["maps.$i.project_task_id" => 'Map to a leaf planning activity, not a planning head.']);
                }

                $model = $this->findMapModel($lockedItem, $task, $row);
                $resolved = $this->resolveManualAllocation(
                    $lockedItem,
                    $task,
                    $row,
                    $model,
                    "maps.$i",
                    $batchClaimedNos,
                    $batchClaimedBoq,
                );
                $batchClaimedNos = $batchClaimedNos->plus($this->resolvedMappedNos($resolved));
                $batchClaimedBoq = $batchClaimedBoq->plus(Decimal::of($resolved['allocated_boq_qty']));
                $resolvedRows[] = ['task' => $task, 'model' => $model, 'resolved' => $resolved, 'row' => $row, 'index' => $i];
            }

            $this->assertNoOverAllocation($lockedItem, $resolvedRows);

            foreach ($resolvedRows as $entry) {
                $model = $this->persistMap($lockedBoq, $lockedItem, $entry['task'], $entry['model'], $entry['resolved'], $entry['row'], $user, $entry['index']);
                $kept[] = $model->id;
                $this->syncTaskFromMap($entry['task'], $model, $entry['resolved']);
            }

            BoqPlanningMap::query()
                ->where('boq_item_id', $lockedItem->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->lockForUpdate()
                ->get()
                ->each(function (BoqPlanningMap $map) use ($user) {
                    if ($this->taskHasApprovedProgress((int) $map->project_task_id)) {
                        throw ValidationException::withMessages([
                            'maps' => 'Cannot remove a mapping that already has approved DPR progress.',
                        ]);
                    }
                    $map->forceFill(['is_active' => false, 'is_primary' => false, 'updated_by' => $user->id])->save();
                });

            return $this->workspacePayload($lockedItem->fresh(['unit:id,symbol', 'boq']));
        });

        return ['payload' => $payload];
    }

    /**
     * Save maps for one Planning Activity (may span multiple BOQ items).
     *
     * @param  list<array<string, mixed>>  $rows  each with boq_item_id + allocation fields
     * @return list<array<string, mixed>>
     */
    public function saveMapsForTask(ProjectTask $task, array $rows, User $user): array
    {
        if ($task->isPlanningHead()) {
            throw ValidationException::withMessages(['maps' => 'Map BOQ items to a leaf planning activity, not a head.']);
        }

        return DB::transaction(function () use ($task, $rows, $user) {
            $lockedTask = ProjectTask::query()->whereKey($task->id)->lockForUpdate()->with('unit:id,symbol')->firstOrFail();
            $kept = [];
            $payloads = [];

            foreach (array_values($rows) as $i => $row) {
                $itemId = (int) ($row['boq_item_id'] ?? 0);
                $item = BoqItem::query()
                    ->whereKey($itemId)
                    ->whereHas('boq', fn ($q) => $q->where('project_id', $lockedTask->project_id))
                    ->with(['unit:id,symbol', 'boq'])
                    ->lockForUpdate()
                    ->first();
                if ($item === null) {
                    throw ValidationException::withMessages(["maps.$i.boq_item_id" => 'Choose a BOQ item from this project.']);
                }

                Boq::query()->whereKey($item->boq_id)->lockForUpdate()->firstOrFail();

                $row['project_task_id'] = $lockedTask->id;
                $model = $this->findMapModel($item, $lockedTask, $row);
                $resolved = $this->resolveManualAllocation($item, $lockedTask, $row, $model, "maps.$i");

                // Per-item over-allocation check including this candidate.
                $this->assertNoOverAllocation($item, [[
                    'task' => $lockedTask,
                    'model' => $model,
                    'resolved' => $resolved,
                    'row' => $row,
                    'index' => $i,
                ]]);

                $isPrimary = (bool) ($row['is_primary'] ?? ($i === 0));
                $resolved['is_primary'] = $isPrimary;

                $saved = $this->persistMap($item->boq, $item, $lockedTask, $model, $resolved, $row, $user, $i);
                if ($isPrimary) {
                    $this->syncTaskFromMap($lockedTask, $saved, $resolved);
                    // Clear other primaries for this task.
                    BoqPlanningMap::query()
                        ->where('project_task_id', $lockedTask->id)
                        ->where('is_active', true)
                        ->whereKeyNot($saved->id)
                        ->update(['is_primary' => false]);
                }
                $kept[] = $saved->id;
                $payloads[] = $this->mapPayload($saved->fresh(['task.unit']));
            }

            BoqPlanningMap::query()
                ->where('project_task_id', $lockedTask->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->lockForUpdate()
                ->get()
                ->each(function (BoqPlanningMap $map) use ($user) {
                    if ($this->taskHasApprovedProgress((int) $map->project_task_id)) {
                        throw ValidationException::withMessages([
                            'maps' => 'Cannot remove a mapping that already has approved DPR progress.',
                        ]);
                    }
                    $map->forceFill(['is_active' => false, 'is_primary' => false, 'updated_by' => $user->id])->save();
                });

            return $payloads;
        });
    }

    /**
     * Active maps for a planning task.
     *
     * @return list<array<string, mixed>>
     */
    public function mapsForTask(ProjectTask $task): array
    {
        return BoqPlanningMap::query()
            ->where('project_task_id', $task->id)
            ->where('is_active', true)
            ->with(['item:id,name,item_code,quantity,measurement_nob,measurement_nos,unit_id', 'item.unit:id,symbol', 'boq:id,boq_number,version,status,is_current'])
            ->orderByDesc('is_primary')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (BoqPlanningMap $m) => [
                ...$this->mapPayload($m),
                'boq_item_id' => $m->boq_item_id,
                'boq_id' => $m->boq_id,
                'boq_label' => $m->boq
                    ? ($m->boq->boq_number.' / R'.$m->boq->version)
                    : null,
                'item_name' => $m->item?->name,
                'item_code' => $m->item?->item_code,
                'allocation' => $m->item ? $this->allocationSummary($m->item) : null,
            ])
            ->all();
    }

    /**
     * Eligible current BOQ items for Planning mapping on a project.
     *
     * @return list<array<string, mixed>>
     */
    public function eligibleBoqItems(Project $project): array
    {
        $items = BoqItem::query()
            ->whereHas('boq', fn ($q) => $q
                ->where('project_id', $project->id)
                ->where(fn ($w) => $w
                    ->where(fn ($a) => $a->where('status', BoqStatus::Approved)->where('is_current', true))
                    ->orWhere('status', BoqStatus::Draft)))
            ->with(['unit:id,symbol', 'boq:id,boq_number,version,status,is_current,title'])
            ->orderBy('boq_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        return $items->map(function (BoqItem $item) {
            $summary = $this->allocationSummary($item);
            $nos = SimpleBoqMeasurementCalculator::nosBreakdown($item);

            return [
                'value' => $item->id,
                'boq_id' => $item->boq_id,
                'label' => trim(($item->item_code ? $item->item_code.' — ' : '').$item->name),
                'boq_label' => $item->boq
                    ? ($item->boq->boq_number.' / R'.$item->boq->version.($item->boq->is_current ? '' : ' (draft)'))
                    : null,
                'name' => $item->name,
                'item_code' => $item->item_code,
                'unit' => $item->unit?->symbol,
                'quantity' => Decimal::of($item->quantity ?? '0')->toQuantity(),
                'measurement_nob' => $nos['measurement_nob'],
                'nos_per_building' => $nos['nos_per_building'],
                'measurement_nos' => $nos['effective_nos'],
                'effective_nos' => $nos['effective_nos'],
                'allocation' => $summary,
            ];
        })->values()->all();
    }

    /**
     * Active map for a planning task (primary preferred).
     */
    public function activeMapForTask(ProjectTask $task): ?BoqPlanningMap
    {
        return BoqPlanningMap::query()
            ->where('project_task_id', $task->id)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Deactivate every active BOQ mapping for a planning activity (soft-delete / release).
     * Returns the number of maps released so callers can assert availability was freed.
     */
    public function releaseMapsForTask(ProjectTask $task, ?User $user = null): int
    {
        return BoqPlanningMap::query()
            ->where('project_task_id', $task->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'is_primary' => false,
                'updated_by' => $user?->id,
                'updated_at' => now(),
            ]);
    }

    /**
     * Suggested BOQ qty from planning Nos × conversion (conversion_based).
     */
    public function suggestedBoqQty(BoqPlanningMap $map, string $planningExecutedQty): ?string
    {
        if ($map->tracking_basis !== PlanningTrackingBasis::Nos) {
            return Decimal::of($planningExecutedQty)->toQuantity();
        }

        if ($map->mapping_method === PlanningBoqMappingMethod::ActualMeasurement
            || $map->mapping_method === PlanningBoqMappingMethod::ManualCertification) {
            return null;
        }

        $factor = $map->conversion_factor;
        if ($factor === null || Decimal::of($factor)->isZero()) {
            return null;
        }

        return Decimal::of($planningExecutedQty)
            ->times($factor)
            ->round(Decimal::QTY_SCALE)
            ->toQuantity();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolveManualAllocation(
        BoqItem $item,
        ProjectTask $task,
        array $row,
        ?BoqPlanningMap $existing,
        string $errorKey,
        ?Decimal $batchClaimedNos = null,
        ?Decimal $batchClaimedBoq = null,
    ): array {
        $basis = PlanningTrackingBasis::tryFrom((string) ($row['tracking_basis'] ?? ''))
            ?? throw ValidationException::withMessages(["{$errorKey}.tracking_basis" => 'Choose Nos or BOQ Quantity.']);

        $method = PlanningBoqMappingMethod::tryFrom((string) ($row['mapping_method'] ?? PlanningBoqMappingMethod::ConversionBased->value))
            ?? PlanningBoqMappingMethod::ConversionBased;

        $boqQty = Decimal::of($item->quantity ?? '0')->round(Decimal::QTY_SCALE);
        $boqNos = $this->boqNos($item);
        $boqUnit = (string) ($item->unit?->symbol ?? $row['boq_unit_symbol'] ?? '');
        $factor = $this->conversionFactor($item);
        $remaining = $this->remainingCapacity(
            $item,
            $existing?->id,
            $batchClaimedNos ?? Decimal::zero(),
            $batchClaimedBoq ?? Decimal::zero(),
        );

        if ($basis === PlanningTrackingBasis::Nos) {
            if ($boqNos === null || ! $boqNos->isPositive()) {
                throw ValidationException::withMessages([
                    "{$errorKey}.tracking_basis" => 'Nos mapping is unavailable because this BOQ item has no Nos.',
                ]);
            }
            if ($factor === null || ! $factor->isPositive()) {
                throw ValidationException::withMessages([
                    "{$errorKey}.tracking_basis" => 'Cannot derive conversion (BOQ Qty / Nos) for this item.',
                ]);
            }

            $allocatedNos = $this->requirePositiveQty($row['planning_qty'] ?? $row['allocated_planning_qty'] ?? null, "{$errorKey}.planning_qty", 'Enter allocated Nos for this activity.');
            // Full remaining Nos → exact remaining Cum (avoids conversion rounding dust).
            if ($remaining['nos'] !== null && $allocatedNos->equals($remaining['nos'])) {
                $allocatedBoq = $remaining['boq'];
            } else {
                $allocatedBoq = $allocatedNos->times($factor)->round(Decimal::QTY_SCALE);
            }
            $planUnit = 'Nos';
            // Conversion is always derived — ignore client overrides for ordinary conversion_based maps.
            $storedFactor = $factor->toQuantity();
        } else {
            $allocatedBoq = $this->requirePositiveQty(
                $row['allocated_boq_qty'] ?? $row['planning_qty'] ?? $row['allocated_planning_qty'] ?? null,
                "{$errorKey}.allocated_boq_qty",
                'Enter allocated BOQ quantity for this activity.',
            );
            $allocatedNos = null;
            if ($factor !== null && $factor->isPositive()) {
                // Full remaining Cum → exact remaining Nos.
                if ($allocatedBoq->equals($remaining['boq']) && $remaining['nos'] !== null) {
                    $allocatedNos = $remaining['nos'];
                } else {
                    $allocatedNos = $allocatedBoq->dividedBy($factor, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
                }
            }
            $planUnit = $boqUnit !== '' ? $boqUnit : (string) ($task->unit?->symbol ?? '');
            $storedFactor = $factor?->toQuantity();
            // Planning qty equals allocated BOQ qty on BOQ Quantity basis.
            $planQty = $allocatedBoq;
        }

        if ($basis === PlanningTrackingBasis::Nos) {
            $planQty = $allocatedNos;
        }

        if ($existing !== null) {
            $this->assertProgressSafeChange($existing, $task, $basis, $planQty, $errorKey);
        }

        $calc = PlanningBoqMappingCalculator::line([
            'mapping_method' => $method->value,
            'allocated_boq_qty' => $allocatedBoq->toQuantity(),
            'planning_qty' => $planQty->toQuantity(),
            'conversion_factor' => $storedFactor,
            'boq_unit_symbol' => $boqUnit,
            'planning_unit_symbol' => $planUnit,
        ]);

        return [
            'mapping_method' => $calc['mapping_method'],
            'tracking_basis' => $basis->value,
            'allocated_boq_qty' => $calc['allocated_boq_qty'],
            'planning_qty' => $calc['planning_qty'],
            'conversion_factor' => $calc['conversion_factor'],
            'boq_unit_symbol' => $boqUnit !== '' ? $boqUnit : null,
            'planning_unit_symbol' => $planUnit !== '' ? $planUnit : null,
            'snapshot_boq_nos' => $boqNos?->toQuantity(),
            'snapshot_boq_qty' => $boqQty->toQuantity(),
            'snapshot_boq_unit' => $boqUnit !== '' ? $boqUnit : null,
            'snapshot_planning_unit' => $planUnit !== '' ? $planUnit : null,
            'equivalent_nos' => $basis === PlanningTrackingBasis::BoqQuantity
                ? ($allocatedNos?->toQuantity())
                : $planQty->toQuantity(),
            'is_primary' => (bool) ($row['is_primary'] ?? false),
        ];
    }

    /**
     * @param  list<array{task: ProjectTask, model: ?BoqPlanningMap, resolved: array<string, mixed>, row: array<string, mixed>, index: int}>  $resolvedRows
     */
    private function assertNoOverAllocation(BoqItem $item, array $resolvedRows): void
    {
        $boqQty = Decimal::of($item->quantity ?? '0');
        $excludeIds = collect($resolvedRows)
            ->map(fn (array $e) => $e['model']?->id)
            ->filter()
            ->all();

        $existing = $this->activeMapsOnLiveTasksQuery($item->id)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        $total = Decimal::zero();
        foreach ($existing as $map) {
            $total = $total->plus($this->equivalentBoqQty($map, $item));
        }
        foreach ($resolvedRows as $entry) {
            if ((int) ($entry['resolved']['boq_item_id'] ?? $item->id) !== (int) $item->id
                && isset($entry['row']['boq_item_id'])
                && (int) $entry['row']['boq_item_id'] !== (int) $item->id) {
                continue;
            }
            // Rows in saveMaps are always for this item; saveMapsForTask filters per call.
            if (isset($entry['row']['boq_item_id']) && (int) $entry['row']['boq_item_id'] !== (int) $item->id) {
                continue;
            }
            $total = $total->plus(Decimal::of($entry['resolved']['allocated_boq_qty']));
        }

        if ($total->greaterThan($boqQty)) {
            $summary = $this->allocationSummary($item, $excludeIds[0] ?? null);
            throw ValidationException::withMessages([
                'maps' => 'Allocation exceeds available BOQ quantity ('
                    .$summary['available_boq_qty'].' '.$summary['boq_unit'].' available). Total would be '
                    .$total->round(Decimal::QTY_SCALE)->toQuantity().' against '
                    .$boqQty->toQuantity().'.',
            ]);
        }
    }

    private function assertProgressSafeChange(
        BoqPlanningMap $existing,
        ProjectTask $task,
        PlanningTrackingBasis $basis,
        Decimal $newPlanQty,
        string $errorKey,
    ): void {
        if (! $this->taskHasApprovedProgress((int) $task->id)) {
            return;
        }

        if ($existing->tracking_basis !== null && $existing->tracking_basis !== $basis) {
            throw ValidationException::withMessages([
                "{$errorKey}.tracking_basis" => 'Tracking basis cannot change after approved DPR progress exists.',
            ]);
        }

        $executed = Decimal::of($task->completed_qty ?? '0');
        if ($newPlanQty->lessThan($executed)) {
            throw ValidationException::withMessages([
                "{$errorKey}.planning_qty" => 'Cannot reduce allocation below executed quantity ('.$executed->toQuantity().').',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>  $row
     */
    private function persistMap(
        Boq $boq,
        BoqItem $item,
        ProjectTask $task,
        ?BoqPlanningMap $model,
        array $resolved,
        array $row,
        User $user,
        int $sequence,
    ): BoqPlanningMap {
        if ($model === null) {
            $model = new BoqPlanningMap;
            $model->forceFill([
                'company_id' => $boq->company_id,
                'project_id' => $boq->project_id,
                'boq_id' => $boq->id,
                'boq_item_id' => $item->id,
                'boq_line_uid' => $item->line_uid,
                'created_by' => $user->id,
            ]);
        }

        $model->forceFill([
            'project_task_id' => $task->id,
            'mapping_method' => $resolved['mapping_method'],
            'tracking_basis' => $resolved['tracking_basis'],
            'allocated_boq_qty' => $resolved['allocated_boq_qty'],
            'boq_unit_symbol' => $resolved['boq_unit_symbol'],
            'planning_qty' => $resolved['planning_qty'],
            'planning_unit_symbol' => $resolved['planning_unit_symbol'],
            'conversion_factor' => $resolved['conversion_factor'],
            'snapshot_boq_nos' => $resolved['snapshot_boq_nos'],
            'snapshot_boq_qty' => $resolved['snapshot_boq_qty'],
            'snapshot_boq_unit' => $resolved['snapshot_boq_unit'],
            'snapshot_planning_unit' => $resolved['snapshot_planning_unit'],
            'remarks' => isset($row['remarks']) ? (string) $row['remarks'] : null,
            'sequence' => $sequence,
            'is_active' => true,
            'is_primary' => (bool) ($resolved['is_primary'] ?? $model->is_primary ?? false),
            'updated_by' => $user->id,
        ])->save();

        return $model;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function syncTaskFromMap(ProjectTask $task, BoqPlanningMap $map, array $resolved): void
    {
        if (! ($resolved['is_primary'] ?? $map->is_primary)) {
            // From BOQ-side saveMaps: treat each row as updating that task's plan from this map
            // when it is the only/primary map for the task.
            $otherPrimary = BoqPlanningMap::query()
                ->where('project_task_id', $task->id)
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereKeyNot($map->id)
                ->exists();
            if ($otherPrimary) {
                return;
            }
            $map->forceFill(['is_primary' => true])->save();
        }

        $unitId = $task->unit_id;
        $planUnit = (string) ($resolved['planning_unit_symbol'] ?? '');
        if ($planUnit !== '') {
            $found = Unit::query()->where('symbol', $planUnit)->value('id');
            if ($found) {
                $unitId = (int) $found;
            }
        }

        $task->forceFill([
            'planned_qty' => $resolved['planning_qty'],
            'unit_id' => $unitId,
            'boq_item_id' => $map->boq_item_id,
        ])->save();

        // Cost WBS inherits from BOQ item when present (Planning schedule wbs_code is separate).
        if ($map->boq_item_id) {
            $boqItem = $map->relationLoaded('item') ? $map->item : BoqItem::query()->find($map->boq_item_id);
            if ($boqItem?->project_cost_head_id) {
                app(ProjectWbsService::class)->inheritFromBoqOntoTask($task->fresh(), $boqItem);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findMapModel(BoqItem $item, ProjectTask $task, array $row): ?BoqPlanningMap
    {
        if (! empty($row['id'])) {
            $model = BoqPlanningMap::query()
                ->whereKey($row['id'])
                ->where('boq_item_id', $item->id)
                ->lockForUpdate()
                ->first();
            if ($model !== null) {
                return $model;
            }
        }

        return BoqPlanningMap::query()
            ->where('boq_item_id', $item->id)
            ->where('project_task_id', $task->id)
            ->lockForUpdate()
            ->first();
    }

    private function equivalentBoqQty(BoqPlanningMap $map, BoqItem $item): Decimal
    {
        // Stored allocated_boq_qty is authoritative (includes full-Nos / full-Cum snaps).
        if ($map->allocated_boq_qty !== null && $map->allocated_boq_qty !== '') {
            return Decimal::of($map->allocated_boq_qty);
        }

        if ($map->tracking_basis === PlanningTrackingBasis::BoqQuantity) {
            return Decimal::zero();
        }

        $factor = $map->conversion_factor !== null
            ? Decimal::of($map->conversion_factor)
            : $this->conversionFactor($item);

        if ($factor === null || ! $factor->isPositive()) {
            return Decimal::zero();
        }

        return Decimal::of($map->planning_qty ?? '0')->times($factor)->round(Decimal::QTY_SCALE);
    }

    /**
     * Remaining Nos / BOQ qty for a map, excluding an existing map and prior rows in the same save batch.
     *
     * @return array{nos: ?Decimal, boq: Decimal}
     */
    private function remainingCapacity(
        BoqItem $item,
        ?int $exceptMapId,
        Decimal $batchClaimedNos,
        Decimal $batchClaimedBoq,
    ): array {
        $summary = $this->allocationSummary($item, $exceptMapId);
        $remainingBoq = Decimal::of($summary['available_boq_qty'])->minus($batchClaimedBoq);
        if ($remainingBoq->isNegative()) {
            $remainingBoq = Decimal::zero();
        }

        $remainingNos = null;
        if ($summary['available_nos'] !== null) {
            $remainingNos = Decimal::of($summary['available_nos'])->minus($batchClaimedNos);
            if ($remainingNos->isNegative()) {
                $remainingNos = Decimal::zero();
            }
        }

        return [
            'nos' => $remainingNos,
            'boq' => $remainingBoq,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function resolvedMappedNos(array $resolved): Decimal
    {
        if (($resolved['tracking_basis'] ?? '') === PlanningTrackingBasis::Nos->value) {
            return Decimal::of($resolved['planning_qty'] ?? '0');
        }

        if (($resolved['equivalent_nos'] ?? null) !== null && $resolved['equivalent_nos'] !== '') {
            return Decimal::of($resolved['equivalent_nos']);
        }

        return Decimal::zero();
    }

    /**
     * Active maps whose planning activity still exists and is not soft-deleted.
     *
     * @return Builder<BoqPlanningMap>
     */
    private function activeMapsOnLiveTasksQuery(int $boqItemId, ?int $exceptMapId = null): Builder
    {
        return BoqPlanningMap::query()
            ->where('boq_item_id', $boqItemId)
            ->where('is_active', true)
            ->whereHas('task')
            ->when($exceptMapId, fn ($q) => $q->whereKeyNot($exceptMapId));
    }

    private function conversionFactor(BoqItem $item): ?Decimal
    {
        return SimpleBoqMeasurementCalculator::conversionFactorFromItem($item);
    }

    /**
     * Effective BOQ Nos for Planning = NOB × Nos (historical null NOB → 1).
     * Raw Nos alone is never used as the allocation ceiling.
     */
    private function boqNos(BoqItem $item): ?Decimal
    {
        return SimpleBoqMeasurementCalculator::effectiveNosFromItem($item);
    }

    private function requirePositiveQty(mixed $value, string $key, string $message): Decimal
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([$key => $message]);
        }
        $qty = Decimal::of((string) $value)->round(Decimal::QTY_SCALE);
        if (! $qty->isPositive()) {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $qty;
    }

    private function taskHasApprovedProgress(int $taskId): bool
    {
        return ProgressEntry::query()
            ->where('task_id', $taskId)
            ->whereNull('reverses_id')
            ->whereNotIn('id', ProgressEntry::query()->whereNotNull('reverses_id')->select('reverses_id'))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function boqItemSnapshot(BoqItem $item): array
    {
        $summary = $this->allocationSummary($item);
        $nos = SimpleBoqMeasurementCalculator::nosBreakdown($item);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'item_code' => $item->item_code,
            'quantity' => Decimal::of($item->quantity ?? '0')->toQuantity(),
            'unit_symbol' => $item->unit?->symbol,
            'measurement_nob' => $nos['measurement_nob'],
            'nos_per_building' => $nos['nos_per_building'],
            'measurement_nos' => $nos['effective_nos'],
            'effective_nos' => $nos['effective_nos'],
            'conversion_factor' => $summary['conversion_factor'],
            'nos_mapping_available' => $summary['nos_mapping_available'],
            'allocation' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPayload(BoqPlanningMap $map): array
    {
        $equivNos = null;
        if ($map->tracking_basis === PlanningTrackingBasis::BoqQuantity
            && $map->conversion_factor !== null
            && Decimal::of($map->conversion_factor)->isPositive()) {
            $allocated = Decimal::of($map->allocated_boq_qty ?? '0');
            $item = $map->relationLoaded('item') ? $map->item : $map->item()->first();
            if ($item !== null) {
                $remaining = $this->remainingCapacity($item, (int) $map->id, Decimal::zero(), Decimal::zero());
                if ($remaining['nos'] !== null && $allocated->equals($remaining['boq'])) {
                    $equivNos = $remaining['nos']->toQuantity();
                }
            }
            if ($equivNos === null) {
                $equivNos = $allocated
                    ->dividedBy($map->conversion_factor, Decimal::QTY_SCALE)
                    ->round(Decimal::QTY_SCALE)
                    ->toQuantity();
            }
        }

        return [
            'id' => $map->id,
            'project_task_id' => $map->project_task_id,
            'task_label' => trim(($map->task?->wbs_code ? $map->task->wbs_code.' — ' : '').($map->task?->name ?? '')),
            'mapping_method' => $map->mapping_method?->value ?? PlanningBoqMappingMethod::ConversionBased->value,
            'tracking_basis' => $map->tracking_basis?->value ?? PlanningTrackingBasis::BoqQuantity->value,
            'allocated_boq_qty' => Decimal::of($map->allocated_boq_qty)->toQuantity(),
            'allocated_planning_qty' => Decimal::of($map->planning_qty)->toQuantity(),
            'boq_unit_symbol' => $map->boq_unit_symbol,
            'planning_qty' => Decimal::of($map->planning_qty)->toQuantity(),
            'planning_unit_symbol' => $map->planning_unit_symbol,
            'conversion_factor' => $map->conversion_factor !== null
                ? Decimal::of($map->conversion_factor)->toQuantity()
                : null,
            'equivalent_nos' => $equivNos,
            'snapshot_boq_nos' => $map->snapshot_boq_nos !== null
                ? Decimal::of($map->snapshot_boq_nos)->toQuantity()
                : null,
            'snapshot_boq_qty' => $map->snapshot_boq_qty !== null
                ? Decimal::of($map->snapshot_boq_qty)->toQuantity()
                : null,
            'snapshot_boq_unit' => $map->snapshot_boq_unit,
            'snapshot_planning_unit' => $map->snapshot_planning_unit,
            'units_compatible_warning' => $map->boq_unit_symbol
                && $map->planning_unit_symbol
                && strcasecmp((string) $map->boq_unit_symbol, (string) $map->planning_unit_symbol) !== 0,
            'needs_review' => (bool) $map->needs_review,
            'review_reason' => $map->review_reason,
            'is_primary' => (bool) $map->is_primary,
            'remarks' => $map->remarks,
            'sequence' => $map->sequence,
            'progress_locked' => $map->project_task_id
                ? $this->taskHasApprovedProgress((int) $map->project_task_id)
                : false,
        ];
    }
}
