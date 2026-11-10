<?php

namespace App\Services\Boq;

use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\CostHead;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItemMaterialCost;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Syncs simplified BOQ material requirements into draft project budget material lines.
 * Approved budgets are never mutated; a pending flag signals that a revision is required.
 */
class BoqBudgetMaterialSyncService
{
    public function __construct(
        private readonly ProjectBudgetService $budgets,
        private readonly UnitConversionService $conversions,
    ) {}

    /**
     * Called after BOQ items/materials are saved. Updates draft budget only.
     *
     * @return array{status: string, budget_id: ?int, message: string}
     */
    public function syncAfterBoqMaterialsChanged(Boq $boq): array
    {
        $project = $boq->project ?? Project::query()->findOrFail($boq->project_id);

        if ((int) $boq->company_id !== (int) $project->company_id
            || (int) $boq->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['boq_id' => 'BOQ does not belong to this project.']);
        }

        $draft = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('status', BudgetStatus::Draft)
            ->first();

        if ($draft !== null) {
            $synced = $this->applyToDraft($draft, $boq);

            return [
                'status' => 'synced',
                'budget_id' => $synced->id,
                'message' => 'Draft budget materials synced from BOQ.',
            ];
        }

        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('status', BudgetStatus::Approved)
            ->first();

        if ($approved !== null) {
            $approved->forceFill(['materials_sync_pending' => true])->save();

            return [
                'status' => 'pending_revision',
                'budget_id' => $approved->id,
                'message' => 'Budget requires revision: BOQ material changes are pending a budget update.',
            ];
        }

        return [
            'status' => 'skipped',
            'budget_id' => null,
            'message' => 'No project budget to sync.',
        ];
    }

    /**
     * Manual / generate path: sync into the project's draft budget (creating one via draftFor if needed).
     */
    public function syncDraftFromBoq(Project $project, Boq $boq): ProjectBudget
    {
        if ((int) $boq->project_id !== (int) $project->id || (int) $boq->company_id !== (int) $project->company_id) {
            throw ValidationException::withMessages(['boq_id' => 'Choose a BOQ of this project.']);
        }

        return DB::transaction(function () use ($project, $boq) {
            $budget = $this->budgets->ensureDraft($project);
            $budget->assertEditable();

            return $this->applyToDraft($budget, $boq);
        });
    }

    public function applyToDraft(ProjectBudget $budget, Boq $boq): ProjectBudget
    {
        $budget->assertEditable();

        if ((int) $budget->project_id !== (int) $boq->project_id) {
            throw ValidationException::withMessages(['budget' => 'Budget and BOQ must belong to the same project.']);
        }

        return DB::transaction(function () use ($budget, $boq) {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $groups = $this->aggregateMaterials($boq);

            $locked->lines()
                ->where('source_type', BudgetLineSource::BoqMaterial->value)
                ->get()
                ->each->delete();

            foreach ($groups as $group) {
                $line = new ProjectBudgetLine;
                $line->forceFill([
                    'cost_head' => CostHead::Material->value,
                    'boq_item_id' => null,
                    'source_type' => BudgetLineSource::BoqMaterial->value,
                    'source_key' => $group['source_key'],
                    'material_id' => $group['material_id'],
                    'material_code' => $group['material_code'],
                    'material_name' => $group['material_name'],
                    'unit' => $group['unit'],
                    'quantity' => $group['quantity'],
                    'rate' => $group['rate'],
                    'description' => $group['description'],
                    'amount' => $group['amount'],
                    'sources' => $group['sources'],
                ]);
                $line->budget()->associate($locked);
                $line->save();
            }

            $locked->forceFill([
                'source' => \App\Enums\Boq\BudgetSource::Boq,
                'boq_id' => $boq->id,
                'materials_synced_at' => now(),
                'materials_sync_pending' => false,
            ])->save();

            ProjectBudget::query()
                ->where('project_id', $locked->project_id)
                ->where('status', BudgetStatus::Approved)
                ->where('materials_sync_pending', true)
                ->update(['materials_sync_pending' => false]);

            return $this->budgets->recalculate($locked->fresh());
        });
    }

    /**
     * @return list<array{
     *   source_key: string,
     *   material_id: ?int,
     *   material_code: ?string,
     *   material_name: string,
     *   unit: string,
     *   quantity: string,
     *   rate: string,
     *   amount: string,
     *   description: string,
     *   sources: list<array<string, mixed>>
     * }>
     */
    public function aggregateMaterials(Boq $boq): array
    {
        $rows = BoqItemMaterialCost::query()
            ->where('boq_id', $boq->id)
            ->where('project_id', $boq->project_id)
            ->where('company_id', $boq->company_id)
            ->with(['item:id,line_uid,item_code,name,quantity', 'material:id,code,name'])
            ->orderBy('boq_item_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $perUnitQty = Decimal::of((string) $row->quantity);
            if (! $perUnitQty->isPositive()) {
                continue;
            }

            $item = $row->item;
            if ($item === null || (int) $item->id !== (int) $row->boq_item_id) {
                continue;
            }

            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $rate = Decimal::of((string) $row->rate)->toRate();
            $calculatedQty = Decimal::of($perUnitQty->toQuantity())->times($boqQty)->toQuantity();
            $amount = Decimal::of($calculatedQty)->times($rate)->toMoney();

            $unit = trim((string) $row->unit);
            $materialId = $row->material_id !== null ? (int) $row->material_id : null;
            $materialName = trim((string) ($row->material?->name ?? $row->material_name));
            $materialCode = $row->material?->code;

            if ($materialName === '' || $unit === '') {
                continue;
            }

            $key = $this->aggregationKey($materialId, $materialName, $unit);
            $canonicalUnit = $unit;
            $canonicalQty = Decimal::of($calculatedQty);
            $canonicalAmount = Decimal::of($amount);

            if ($materialId !== null) {
                foreach ($groups as $existingKey => $existing) {
                    if (($existing['material_id'] ?? null) !== $materialId) {
                        continue;
                    }

                    if (mb_strtolower((string) $existing['unit']) === mb_strtolower($unit)) {
                        $key = $existingKey;
                        $canonicalUnit = (string) $existing['unit'];
                        break;
                    }

                    $converted = $this->tryConvert(
                        (int) $boq->company_id,
                        (int) $boq->project_id,
                        $materialId,
                        $unit,
                        (string) $existing['unit'],
                        $calculatedQty,
                    );
                    if ($converted !== null) {
                        $key = $existingKey;
                        $canonicalUnit = (string) $existing['unit'];
                        $canonicalQty = Decimal::of($converted);
                        $canonicalAmount = Decimal::of($canonicalQty)->times($rate);
                        break;
                    }
                }
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'source_key' => $key,
                    'material_id' => $materialId,
                    'material_code' => $materialCode,
                    'material_name' => $materialName,
                    'unit' => $canonicalUnit,
                    'quantity' => Decimal::zero(),
                    'amount' => Decimal::zero(),
                    'sources' => [],
                ];
            }

            $groups[$key]['quantity'] = Decimal::of($groups[$key]['quantity'])->plus($canonicalQty);
            $groups[$key]['amount'] = Decimal::of($groups[$key]['amount'])->plus($canonicalAmount);
            if ($materialCode && ! $groups[$key]['material_code']) {
                $groups[$key]['material_code'] = $materialCode;
            }

            $groups[$key]['sources'][] = [
                'boq_id' => $boq->id,
                'boq_number' => $boq->boq_number,
                'boq_version' => $boq->version,
                'boq_item_id' => $item->id,
                'boq_line_uid' => $item->line_uid,
                'item_code' => $item->item_code,
                'item_name' => $item->name,
                'boq_quantity' => $boqQty,
                'per_unit_quantity' => $perUnitQty->toQuantity(),
                'calculated_quantity' => $calculatedQty,
                'unit' => $unit,
                'rate' => $rate,
                'amount' => $amount,
            ];
        }

        $result = [];
        foreach ($groups as $group) {
            $qty = Decimal::of($group['quantity'])->toQuantity();
            $totalAmount = Decimal::of($group['amount'])->toMoney();
            $weightedRate = Decimal::of($qty)->isZero()
                ? '0.0000'
                : Decimal::of($totalAmount)->dividedBy($qty)->toRate();

            $label = trim(($group['material_code'] ? $group['material_code'].' ' : '').$group['material_name']);

            $result[] = [
                'source_key' => $group['source_key'],
                'material_id' => $group['material_id'],
                'material_code' => $group['material_code'],
                'material_name' => $group['material_name'],
                'unit' => $group['unit'],
                'quantity' => $qty,
                'rate' => $weightedRate,
                'amount' => $totalAmount,
                'description' => mb_substr($label !== '' ? $label : $group['material_name'], 0, 255),
                'sources' => $group['sources'],
            ];
        }

        usort($result, fn (array $a, array $b) => strcasecmp($a['material_name'], $b['material_name']));

        return $result;
    }

    private function aggregationKey(?int $materialId, string $materialName, string $unit): string
    {
        if ($materialId !== null) {
            return 'm:'.$materialId.'|u:'.mb_strtolower(trim($unit));
        }

        return 'n:'.mb_strtolower(trim($materialName)).'|u:'.mb_strtolower(trim($unit));
    }

    private function tryConvert(
        int $companyId,
        int $projectId,
        int $materialId,
        string $fromUnit,
        string $toUnit,
        string $quantity,
    ): ?string {
        try {
            return $this->conversions->convert($companyId, $projectId, $materialId, $fromUnit, $toUnit, $quantity)['result'];
        } catch (ValidationException) {
            return null;
        }
    }
}
