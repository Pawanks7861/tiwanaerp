<?php

namespace App\Services\Boq;

use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Enums\Boq\PlanningTrackingBasis;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqPlanningMap;
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectTask;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional BOQ ↔ Planning activity quantity mapping.
 * Does not require BOQ when creating Planning; mapping is additive.
 * Tracking basis: Nos or BOQ Quantity (units need not match).
 */
class PlanningBoqMappingService
{
    /**
     * @return array{
     *   maps: list<array<string, mixed>>,
     *   mapping_methods: list<array{value: string, label: string}>,
     *   tracking_bases: list<array{value: string, label: string}>,
     *   boq_item: array<string, mixed>,
     *   locked: bool
     * }
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);
        $maps = BoqPlanningMap::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->with(['task:id,name,wbs_code,planned_qty,unit_id', 'task.unit:id,symbol'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return [
            'maps' => $maps->map(fn (BoqPlanningMap $m) => $this->mapPayload($m))->all(),
            'mapping_methods' => PlanningBoqMappingMethod::options(),
            'tracking_bases' => PlanningTrackingBasis::options(),
            'boq_item' => $this->boqItemSnapshot($item),
            'locked' => ! $item->boq->isEditable(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{payload: array<string, mixed>}
     */
    public function saveMaps(BoqItem $item, array $rows, User $user): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);
        $boq = $item->boq;
        $boq->assertEditable();

        $payload = DB::transaction(function () use ($item, $rows, $user, $boq) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->with('unit:id,symbol')->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            $lockedBoq->assertEditable();

            $kept = [];
            foreach (array_values($rows) as $i => $row) {
                $taskId = (int) ($row['project_task_id'] ?? 0);
                $task = ProjectTask::query()
                    ->whereKey($taskId)
                    ->where('project_id', $lockedBoq->project_id)
                    ->with('unit:id,symbol')
                    ->first();
                if ($task === null) {
                    throw ValidationException::withMessages(["maps.$i.project_task_id" => 'Choose a planning activity on this project.']);
                }
                if ($task->isPlanningHead()) {
                    throw ValidationException::withMessages(["maps.$i.project_task_id" => 'Map to a leaf planning activity, not a planning head.']);
                }

                $resolved = $this->resolveTrackingRow($lockedItem, $task, $row, "maps.$i");

                $model = null;
                if (! empty($row['id'])) {
                    $model = BoqPlanningMap::query()
                        ->whereKey($row['id'])
                        ->where('boq_item_id', $lockedItem->id)
                        ->lockForUpdate()
                        ->first();
                }
                if ($model === null) {
                    $model = BoqPlanningMap::query()
                        ->where('boq_item_id', $lockedItem->id)
                        ->where('project_task_id', $task->id)
                        ->lockForUpdate()
                        ->first();
                }

                if ($model !== null && $model->tracking_basis !== null
                    && $model->tracking_basis->value !== $resolved['tracking_basis']
                    && $this->taskHasApprovedProgress((int) $task->id)) {
                    throw ValidationException::withMessages([
                        "maps.$i.tracking_basis" => 'Tracking basis cannot change after approved DPR progress exists for this activity.',
                    ]);
                }

                if ($model === null) {
                    $model = new BoqPlanningMap;
                    $model->forceFill([
                        'company_id' => $lockedBoq->company_id,
                        'project_id' => $lockedBoq->project_id,
                        'boq_id' => $lockedBoq->id,
                        'boq_item_id' => $lockedItem->id,
                        'boq_line_uid' => $lockedItem->line_uid,
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
                    'sequence' => $i,
                    'is_active' => true,
                    'updated_by' => $user->id,
                ])->save();

                $kept[] = $model->id;
            }

            BoqPlanningMap::query()
                ->where('boq_item_id', $lockedItem->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->get()
                ->each(function (BoqPlanningMap $map) use ($user) {
                    $map->forceFill(['is_active' => false, 'updated_by' => $user->id])->save();
                });

            return $this->workspacePayload($lockedItem->fresh(['unit:id,symbol']));
        });

        return ['payload' => $payload];
    }

    /**
     * Active map for a planning task (any BOQ item on the project).
     */
    public function activeMapForTask(ProjectTask $task): ?BoqPlanningMap
    {
        return BoqPlanningMap::query()
            ->where('project_task_id', $task->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
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
     * @return array{
     *   mapping_method: string,
     *   tracking_basis: string,
     *   allocated_boq_qty: string,
     *   planning_qty: string,
     *   conversion_factor: string|null,
     *   boq_unit_symbol: string|null,
     *   planning_unit_symbol: string|null,
     *   snapshot_boq_nos: string|null,
     *   snapshot_boq_qty: string,
     *   snapshot_boq_unit: string|null,
     *   snapshot_planning_unit: string|null
     * }
     */
    private function resolveTrackingRow(BoqItem $item, ProjectTask $task, array $row, string $errorKey): array
    {
        $basis = PlanningTrackingBasis::tryFrom((string) ($row['tracking_basis'] ?? PlanningTrackingBasis::BoqQuantity->value))
            ?? throw ValidationException::withMessages(["{$errorKey}.tracking_basis" => 'Choose Nos or BOQ Quantity tracking.']);

        $boqQty = Decimal::of($item->quantity ?? '0')->round(Decimal::QTY_SCALE);
        $boqNos = $item->measurement_nos !== null && $item->measurement_nos !== ''
            ? Decimal::of($item->measurement_nos)->round(Decimal::QTY_SCALE)
            : null;
        $boqUnit = (string) ($row['boq_unit_symbol'] ?? $item->unit?->symbol ?? '');

        if ($basis === PlanningTrackingBasis::Nos) {
            if ($boqNos === null || ! $boqNos->isPositive()) {
                throw ValidationException::withMessages([
                    "{$errorKey}.tracking_basis" => 'Enter BOQ Nos before using Nos-based Planning.',
                ]);
            }

            $planQty = $boqNos;
            $planUnit = (string) ($row['planning_unit_symbol'] ?? 'Nos');
            $allocated = array_key_exists('allocated_boq_qty', $row) && $row['allocated_boq_qty'] !== null && $row['allocated_boq_qty'] !== ''
                ? Decimal::of($row['allocated_boq_qty'])->round(Decimal::QTY_SCALE)
                : $boqQty;

            if ($boqNos->isZero()) {
                throw ValidationException::withMessages([
                    "{$errorKey}.tracking_basis" => 'Enter BOQ Nos before using Nos-based Planning.',
                ]);
            }

            $factor = $allocated->dividedBy($boqNos, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
        } else {
            $planQty = array_key_exists('planning_qty', $row) && $row['planning_qty'] !== null && $row['planning_qty'] !== ''
                ? Decimal::of($row['planning_qty'])->round(Decimal::QTY_SCALE)
                : $boqQty;
            $planUnit = (string) ($row['planning_unit_symbol'] ?? $task->unit?->symbol ?? $boqUnit);
            $allocated = array_key_exists('allocated_boq_qty', $row) && $row['allocated_boq_qty'] !== null && $row['allocated_boq_qty'] !== ''
                ? Decimal::of($row['allocated_boq_qty'])->round(Decimal::QTY_SCALE)
                : $boqQty;
            $factor = null;
            if ($planQty->isPositive() && strcasecmp($boqUnit, $planUnit) !== 0) {
                $factor = $allocated->dividedBy($planQty, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
            } elseif ($planQty->isPositive() && $allocated->isPositive()) {
                $factor = $allocated->dividedBy($planQty, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
            }
        }

        $calc = PlanningBoqMappingCalculator::line([
            'mapping_method' => (string) ($row['mapping_method'] ?? PlanningBoqMappingMethod::ConversionBased->value),
            'allocated_boq_qty' => $allocated->toQuantity(),
            'planning_qty' => $planQty->toQuantity(),
            'conversion_factor' => array_key_exists('conversion_factor', $row) && $row['conversion_factor'] !== null && $row['conversion_factor'] !== ''
                ? $row['conversion_factor']
                : $factor?->toQuantity(),
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
        ];
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
        return [
            'id' => $item->id,
            'name' => $item->name,
            'item_code' => $item->item_code,
            'quantity' => Decimal::of($item->quantity ?? '0')->toQuantity(),
            'unit_symbol' => $item->unit?->symbol,
            'measurement_nos' => $item->measurement_nos !== null
                ? Decimal::of($item->measurement_nos)->toQuantity()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPayload(BoqPlanningMap $map): array
    {
        return [
            'id' => $map->id,
            'project_task_id' => $map->project_task_id,
            'task_label' => trim(($map->task?->wbs_code ? $map->task->wbs_code.' — ' : '').($map->task?->name ?? '')),
            'mapping_method' => $map->mapping_method?->value ?? PlanningBoqMappingMethod::ConversionBased->value,
            'tracking_basis' => $map->tracking_basis?->value ?? PlanningTrackingBasis::BoqQuantity->value,
            'allocated_boq_qty' => Decimal::of($map->allocated_boq_qty)->toQuantity(),
            'boq_unit_symbol' => $map->boq_unit_symbol,
            'planning_qty' => Decimal::of($map->planning_qty)->toQuantity(),
            'planning_unit_symbol' => $map->planning_unit_symbol,
            'conversion_factor' => $map->conversion_factor !== null
                ? Decimal::of($map->conversion_factor)->toQuantity()
                : null,
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
            'remarks' => $map->remarks,
            'sequence' => $map->sequence,
        ];
    }
}
