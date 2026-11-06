<?php

namespace App\Services\Procurement;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Validates material-request line rows for the universal import engine.
 */
class MaterialRequestImportService
{
    use NormalizesImportValues;

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    public function validateMappedRow(Project $project, array $mapped): array
    {
        $materialCode = $this->str($mapped['material_code'] ?? null);
        if ($materialCode === null) {
            $this->fail(['material_code' => ['Material code is required.']]);
        }

        $material = Material::query()->active()
            ->whereRaw('LOWER(code) = ?', [strtolower($materialCode)])
            ->first();
        if (! $material) {
            $this->fail(['material_code' => ["Unknown material \"{$materialCode}\"."]]);
        }

        $qtyText = $this->number($mapped['quantity'] ?? null);
        if ($qtyText === null || ! is_numeric($qtyText) || Decimal::of($qtyText)->isZero()) {
            $this->fail(['quantity' => ['Enter a quantity greater than zero.']]);
        }

        $unitId = $material->unit_id;
        $unitOverride = $this->str($mapped['unit'] ?? null);
        if ($unitOverride !== null) {
            $unit = Unit::query()
                ->where(function ($q) use ($unitOverride) {
                    $q->whereRaw('LOWER(symbol) = ?', [strtolower($unitOverride)])
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($unitOverride)]);
                })
                ->first();
            if (! $unit) {
                $this->fail(['unit' => ["Unknown unit \"{$unitOverride}\"."]]);
            }
            $unitId = (int) $unit->id;
        }

        $taskId = null;
        $wbs = $this->str($mapped['task_wbs'] ?? null);
        if ($wbs !== null) {
            $task = ProjectTask::query()->where('project_id', $project->id)->whereRaw('LOWER(wbs_code) = ?', [strtolower($wbs)])->first();
            if (! $task) {
                $this->fail(['task_wbs' => ["Task WBS \"{$wbs}\" was not found on this project."]]);
            }
            $taskId = (int) $task->id;
        }

        return [
            'material_id' => (int) $material->id,
            'unit_id' => $unitId,
            'quantity' => Decimal::of($qtyText)->toQuantity(),
            'boq_item_id' => null,
            'task_id' => $taskId,
            'remarks' => $this->str($mapped['remarks'] ?? null),
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function fail(array $errors): never
    {
        throw ValidationException::withMessages($errors);
    }
}
