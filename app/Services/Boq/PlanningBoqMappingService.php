<?php

namespace App\Services\Boq;

use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqPlanningMap;
use App\Models\Planning\ProjectTask;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional Advanced BOQ ↔ Planning activity quantity mapping.
 * Does not require BOQ when creating Planning; mapping is additive.
 */
class PlanningBoqMappingService
{
    /**
     * @return array{
     *   maps: list<array<string, mixed>>,
     *   mapping_methods: list<array{value: string, label: string}>,
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
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
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

                $boqUnit = (string) ($row['boq_unit_symbol'] ?? $lockedItem->unit?->symbol ?? '');
                $planUnit = (string) ($row['planning_unit_symbol'] ?? $task->unit?->symbol ?? '');

                $calc = PlanningBoqMappingCalculator::line([
                    'mapping_method' => (string) ($row['mapping_method'] ?? PlanningBoqMappingMethod::ConversionBased->value),
                    'allocated_boq_qty' => (string) ($row['allocated_boq_qty'] ?? '0'),
                    'planning_qty' => (string) ($row['planning_qty'] ?? $task->planned_qty ?? '0'),
                    'conversion_factor' => $row['conversion_factor'] ?? null,
                    'boq_unit_symbol' => $boqUnit,
                    'planning_unit_symbol' => $planUnit,
                ]);

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
                    'mapping_method' => $calc['mapping_method'],
                    'allocated_boq_qty' => $calc['allocated_boq_qty'],
                    'boq_unit_symbol' => $boqUnit !== '' ? $boqUnit : null,
                    'planning_qty' => $calc['planning_qty'],
                    'planning_unit_symbol' => $planUnit !== '' ? $planUnit : null,
                    'conversion_factor' => $calc['conversion_factor'],
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
     * @return array<string, mixed>
     */
    private function mapPayload(BoqPlanningMap $map): array
    {
        return [
            'id' => $map->id,
            'project_task_id' => $map->project_task_id,
            'task_label' => trim(($map->task?->wbs_code ? $map->task->wbs_code.' — ' : '').($map->task?->name ?? '')),
            'mapping_method' => $map->mapping_method?->value ?? PlanningBoqMappingMethod::ConversionBased->value,
            'allocated_boq_qty' => Decimal::of($map->allocated_boq_qty)->toQuantity(),
            'boq_unit_symbol' => $map->boq_unit_symbol,
            'planning_qty' => Decimal::of($map->planning_qty)->toQuantity(),
            'planning_unit_symbol' => $map->planning_unit_symbol,
            'conversion_factor' => $map->conversion_factor !== null
                ? Decimal::of($map->conversion_factor)->toQuantity()
                : null,
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
