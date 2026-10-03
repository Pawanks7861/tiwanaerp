<?php

namespace App\Services\SiteExecution\Concerns;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\BoqItem;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Services\Inventory\Concerns\ResolvesInventoryLines;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Tenant- and project-safe resolution of the ids and numbers posted on site diary and DPR lines.
 */
trait ResolvesWorkLines
{
    use ResolvesInventoryLines;

    /**
     * Task, BOQ line and unit of a work line. A task linked to a BOQ line implies that line; the
     * unit must match both the task's and the BOQ line's unit (no conversions).
     *
     * @param  array<string, mixed>  $row
     * @return array{task: ?ProjectTask, boq: ?BoqItem, unit: Unit}
     */
    protected function workTarget(Project $project, array $row, string $prefix): array
    {
        $task = $this->task($project, $row['task_id'] ?? null, "{$prefix}.task_id");
        $boqItemId = $row['boq_item_id'] ?? null;
        $boq = null;
        if (! blank($boqItemId)) {
            $boq = is_numeric($boqItemId) ? $this->currentBoqItems($project)->whereKey((int) $boqItemId)->first() : null;
            // A line of a superseded revision of this project's BOQ moves to its current revision.
            if ($boq === null && is_numeric($boqItemId)) {
                $uid = BoqItem::query()->whereKey((int) $boqItemId)->whereHas('boq', fn ($q) => $q->where('project_id', $project->id))->value('line_uid');
                $boq = $uid ? $this->currentBoqItems($project)->where('line_uid', $uid)->first() : null;
            }
            if ($boq === null) {
                throw ValidationException::withMessages(["{$prefix}.boq_item_id" => 'Choose a line of a current approved BOQ of this project.']);
            }
        }

        if ($task?->boq_item_id !== null) {
            if ($boq === null) {
                $boq = $this->currentBoqItems($project)->whereKey($task->boq_item_id)->first();
            } elseif ($boq->id !== (int) $task->boq_item_id) {
                throw ValidationException::withMessages(["{$prefix}.boq_item_id" => 'This task is linked to a different BOQ item.']);
            }
        }

        $unitId = $row['unit_id'] ?? null;
        $unit = blank($unitId)
            ? Unit::query()->whereKey($task?->unit_id ?? $boq?->unit_id)->first()
            : $this->unit($unitId, "{$prefix}.unit_id");
        if ($unit === null) {
            throw ValidationException::withMessages(["{$prefix}.unit_id" => 'Choose the unit of the quantity.']);
        }

        if ($task?->unit_id !== null && (int) $task->unit_id !== $unit->id) {
            throw ValidationException::withMessages(["{$prefix}.unit_id" => "The task is measured in {$this->symbol($task->unit_id)}; use the same unit."]);
        }
        if ($boq?->unit_id !== null && (int) $boq->unit_id !== $unit->id) {
            throw ValidationException::withMessages(["{$prefix}.unit_id" => "The BOQ item is measured in {$this->symbol($boq->unit_id)}; use the same unit."]);
        }

        if ($task === null && $boq === null && blank($row['description'] ?? null)) {
            throw ValidationException::withMessages(["{$prefix}.description" => 'Describe the work, or link it to a task or BOQ item.']);
        }

        return ['task' => $task, 'boq' => $boq, 'unit' => $unit];
    }

    /**
     * Lines of every current approved BOQ of the project (a project may have several BOQs).
     *
     * @return Builder<BoqItem>
     */
    protected function currentBoqItems(Project $project): Builder
    {
        return BoqItem::query()->whereHas('boq', fn ($q) => $q
            ->where('project_id', $project->id)
            ->where('is_current', true)
            ->where('status', BoqStatus::Approved));
    }

    protected function unit(mixed $id, string $key): Unit
    {
        $unit = is_numeric($id) ? Unit::query()->active()->whereKey((int) $id)->first() : null;

        return $unit ?? throw ValidationException::withMessages([$key => 'Choose an active unit.']);
    }

    protected function subcontractor(mixed $id, string $key): ?Subcontractor
    {
        if (blank($id)) {
            return null;
        }
        $subcontractor = is_numeric($id) ? Subcontractor::query()->active()->whereKey((int) $id)->first() : null;

        return $subcontractor ?? throw ValidationException::withMessages([$key => 'Choose an active subcontractor.']);
    }

    protected function labourTrade(mixed $id, string $key): LabourTrade
    {
        $trade = is_numeric($id) ? LabourTrade::query()->active()->whereKey((int) $id)->first() : null;

        return $trade ?? throw ValidationException::withMessages([$key => 'Choose an active labour trade.']);
    }

    protected function equipmentType(mixed $id, string $key): ?EquipmentType
    {
        if (blank($id)) {
            return null;
        }
        $type = is_numeric($id) ? EquipmentType::query()->active()->whereKey((int) $id)->first() : null;

        return $type ?? throw ValidationException::withMessages([$key => 'Choose an active equipment type.']);
    }

    /**
     * Material line: unit defaults to, and must equal, the item's stock unit.
     *
     * @return array{material: Material, unit_id: int}
     */
    protected function materialWithUnit(mixed $materialId, mixed $unitId, string $prefix): array
    {
        $material = $this->material($materialId, "{$prefix}.material_id");
        if (! blank($unitId) && (int) $unitId !== (int) $material->unit_id) {
            throw ValidationException::withMessages(["{$prefix}.unit_id" => "{$material->name} is recorded in {$this->symbol($material->unit_id)}."]);
        }

        return ['material' => $material, 'unit_id' => (int) $material->unit_id];
    }

    protected function hours(mixed $value, string $key): string
    {
        if (blank($value)) {
            return '0.00';
        }

        try {
            $hours = Decimal::of(is_scalar($value) ? (string) $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'Enter valid hours.']);
        }

        if ($hours->isNegative() || $hours->greaterThan('9999.99') || ! $hours->equals($hours->round(2))) {
            throw ValidationException::withMessages([$key => 'Hours must be between 0 and 9999.99 with at most 2 decimals.']);
        }

        return $hours->round(2)->toString();
    }

    protected function headcount(mixed $value, string $key): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 100000) {
            throw ValidationException::withMessages([$key => 'Enter a headcount of at least 1.']);
        }

        return (int) $value;
    }

    private function symbol(int|string|null $unitId): string
    {
        return (string) (Unit::query()->withTrashed()->whereKey($unitId)->value('symbol') ?? 'another unit');
    }
}
