<?php

namespace App\Services\Projects;

use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Masters\Material;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectCostHead;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project Cost Head (WBS) resolution and inheritance.
 *
 * Priority (most specific wins):
 * 1. Explicit / authoritative source line
 * 2. BOQ item
 * 3. Planning activity
 * 4. Work Order / MR source
 * 5. Parent document / section / head default
 * 6. Material category → WBS map (optional)
 * 7. Manual (null = Unallocated — never auto WBS-06 / Contingency)
 */
class ProjectWbsService
{
    public function __construct(
        private readonly ProjectCostHeadService $heads,
    ) {}

    /**
     * Assert the head belongs to the project's company and is active (or already assigned).
     */
    public function assertBelongsToProject(Project $project, ?int $headId, string $errorKey = 'project_cost_head_id'): ?int
    {
        if ($headId === null || $headId === 0) {
            return null;
        }

        $project->loadMissing('company');
        $head = ProjectCostHead::query()
            ->withoutGlobalScopes()
            ->where('company_id', $project->company_id)
            ->whereKey($headId)
            ->first();

        if ($head === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Choose a WBS Code that belongs to this project\'s company.',
            ]);
        }

        return (int) $head->id;
    }

    /**
     * Resolve WBS for a BOQ item: explicit → section default → null (Unallocated).
     */
    public function resolveForBoqItem(BoqItem $item, ?BoqSection $section = null, ?int $explicit = null): ?int
    {
        if ($explicit !== null && $explicit > 0) {
            return $explicit;
        }

        if ($item->project_cost_head_id) {
            return (int) $item->project_cost_head_id;
        }

        $section ??= $item->relationLoaded('section') ? $item->section : $item->section()->first();
        if ($section?->default_project_cost_head_id) {
            return (int) $section->default_project_cost_head_id;
        }

        return null;
    }

    /**
     * Planning Activity WBS priority:
     * BOQ-linked WBS → Activity explicit → Planning Head default → null.
     */
    public function resolveForPlanningTask(ProjectTask $task, ?BoqItem $boqItem = null, ?int $explicit = null): ?int
    {
        if ($explicit !== null && $explicit > 0) {
            // Explicit only wins when no stronger BOQ source — callers pass null when inheriting from BOQ.
            return $explicit;
        }

        $boqItem ??= $task->boq_item_id
            ? ($task->relationLoaded('boqItem') ? $task->boqItem : BoqItem::query()->find($task->boq_item_id))
            : null;

        if ($boqItem?->project_cost_head_id) {
            return (int) $boqItem->project_cost_head_id;
        }

        if ($task->project_cost_head_id) {
            return (int) $task->project_cost_head_id;
        }

        $parent = $task->parent_id
            ? ($task->relationLoaded('parent') ? $task->parent : ProjectTask::query()->find($task->parent_id))
            : null;

        if ($parent?->project_cost_head_id) {
            return (int) $parent->project_cost_head_id;
        }

        return null;
    }

    /**
     * Prefer BOQ item WBS when mapping Planning ↔ BOQ (overrides free activity / head default).
     */
    public function inheritFromBoqOntoTask(ProjectTask $task, BoqItem $item): void
    {
        $headId = $item->project_cost_head_id ? (int) $item->project_cost_head_id : null;
        if ($headId === null) {
            return;
        }

        if ((int) ($task->project_cost_head_id ?? 0) === $headId) {
            return;
        }

        $task->forceFill(['project_cost_head_id' => $headId])->save();
    }

    /**
     * MR line: explicit → BOQ item (line or header) → Planning task → material category map → null.
     *
     * @param  array<string, mixed>  $row
     */
    public function resolveForMaterialRequestLine(
        Project $project,
        array $row,
        ?MaterialRequestItem $existing = null,
        ?int $headerBoqItemId = null,
    ): ?int {
        if (array_key_exists('project_cost_head_id', $row) && filled($row['project_cost_head_id'] ?? null)) {
            return $this->assertBelongsToProject($project, (int) $row['project_cost_head_id'], 'project_cost_head_id');
        }

        $boqItemId = isset($row['boq_item_id']) && filled($row['boq_item_id'] ?? null)
            ? (int) $row['boq_item_id']
            : ($existing?->boq_item_id ? (int) $existing->boq_item_id : $headerBoqItemId);

        if ($boqItemId) {
            $boqItem = BoqItem::query()->whereKey($boqItemId)->first();
            if ($boqItem?->project_cost_head_id) {
                return (int) $boqItem->project_cost_head_id;
            }
        }

        $taskId = isset($row['task_id']) && filled($row['task_id'] ?? null)
            ? (int) $row['task_id']
            : ($existing?->task_id ? (int) $existing->task_id : null);

        if ($taskId) {
            $task = ProjectTask::query()->whereKey($taskId)->first();
            if ($task?->project_cost_head_id) {
                return (int) $task->project_cost_head_id;
            }
        }

        if ($existing?->project_cost_head_id) {
            return (int) $existing->project_cost_head_id;
        }

        $materialId = isset($row['material_id']) ? (int) $row['material_id'] : null;
        if ($materialId) {
            return $this->fromMaterialCategory($project, $materialId);
        }

        return null;
    }

    public function inheritFromMrOntoPoLine(PurchaseOrderItem $line, ?MaterialRequestItem $mrItem): void
    {
        if ($line->project_cost_head_id || $mrItem === null || ! $mrItem->project_cost_head_id) {
            return;
        }

        $line->forceFill(['project_cost_head_id' => $mrItem->project_cost_head_id]);
    }

    public function fromMaterialCategory(Project $project, int $materialId): ?int
    {
        $material = Material::query()->whereKey($materialId)->first(['id', 'material_category_id', 'company_id']);
        if ($material === null || ! $material->material_category_id) {
            return null;
        }

        $mapId = DB::table('material_category_wbs_maps')
            ->where('company_id', $project->company_id)
            ->where('material_category_id', $material->material_category_id)
            ->value('project_cost_head_id');

        return $mapId ? (int) $mapId : null;
    }

    /**
     * @return list<array{id: int, code: string, name: string, label: string, sort_order: int}>
     */
    public function optionsForProject(Project $project): array
    {
        $project->loadMissing('company');

        return $this->heads->options($project->company);
    }

    public function present(?ProjectCostHead $head): ?array
    {
        if ($head === null) {
            return null;
        }

        return [
            'id' => $head->id,
            'code' => $head->code,
            'name' => $head->name,
            'label' => $head->label(),
        ];
    }

    public function unallocatedLabel(): string
    {
        return 'Unallocated';
    }

    /**
     * Sum WBS budget amounts (money strings).
     *
     * @param  array<int|string, mixed>  $amountByHeadId
     */
    public function sumBudgetAmounts(array $amountByHeadId): string
    {
        $total = Decimal::zero();
        foreach ($amountByHeadId as $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }
            $total = $total->plus(Decimal::of((string) $amount));
        }

        return $total->toMoney();
    }
}
