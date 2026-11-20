<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\BudgetType;
use App\Enums\Boq\CostComponentType;
use App\Enums\CostHead;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqItemMaterialCost;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Syncs BOQ costing into the BOQ Estimate draft (never Main Project Budget).
 *
 * Advanced BOQ: workbook-driven cost components + approved BBS steel
 * (ignores legacy simple materials / flat labour_rate).
 * Simplified BOQ: legacy `boq_item_material_costs` + `boq_items.labour_rate`.
 *
 * Approved BOQ Estimate versions are never mutated; materials_sync_pending signals revision.
 */
class BoqBudgetMaterialSyncService
{
    public function __construct(
        private readonly ProjectBudgetService $budgets,
        private readonly UnitConversionService $conversions,
    ) {}

    /**
     * Legacy hook after BOQ item/material saves.
     * Official BOQ Estimate now publishes only on final BOQ approval — draft edits do not mutate it.
     *
     * @return array{status: string, budget_id: ?int, message: string}
     */
    public function syncAfterBoqMaterialsChanged(Boq $boq): array
    {
        return [
            'status' => 'skipped',
            'budget_id' => null,
            'message' => 'BOQ Estimate updates automatically on final BOQ approval only.',
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
            $budget = $this->budgets->ensureDraft($project, BudgetType::BoqEstimate);
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

        if ($budget->budget_type !== BudgetType::BoqEstimate) {
            throw ValidationException::withMessages([
                'budget' => 'BOQ costing syncs into BOQ Estimate only — Main Project Budget is not updated.',
            ]);
        }

        return DB::transaction(function () use ($budget, $boq) {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $locked->lines()
                ->whereIn('source_type', BudgetLineSource::syncOwnedValues())
                ->get()
                ->each->delete();

            $this->writeProposedLines($locked, $boq);

            $locked->forceFill([
                'budget_type' => BudgetType::BoqEstimate,
                'source' => BudgetSource::Boq,
                'boq_id' => $boq->id,
                'materials_synced_at' => now(),
                'materials_sync_pending' => false,
            ])->save();

            ProjectBudget::query()
                ->where('project_id', $locked->project_id)
                ->boqEstimate()
                ->where('status', BudgetStatus::Approved)
                ->where('materials_sync_pending', true)
                ->update(['materials_sync_pending' => false]);

            return $this->budgets->recalculate($locked->fresh());
        });
    }

    /**
     * Append one BOQ's proposed costing onto a draft estimate without clearing existing lines.
     * Used when aggregating multiple current approved BOQs into one Project BOQ Estimate.
     */
    public function appendBoqToDraft(ProjectBudget $budget, Boq $boq): ProjectBudget
    {
        $budget->assertEditable();

        if ((int) $budget->project_id !== (int) $boq->project_id) {
            throw ValidationException::withMessages(['budget' => 'Budget and BOQ must belong to the same project.']);
        }

        if ($budget->budget_type !== BudgetType::BoqEstimate) {
            throw ValidationException::withMessages([
                'budget' => 'BOQ costing syncs into BOQ Estimate only — Main Project Budget is not updated.',
            ]);
        }

        $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
        $locked->assertEditable();
        $this->writeProposedLines($locked, $boq, prefixSourceKey: true);

        $locked->forceFill([
            'budget_type' => BudgetType::BoqEstimate,
            'source' => BudgetSource::Boq,
            'boq_id' => $boq->id,
            'materials_synced_at' => now(),
            'materials_sync_pending' => false,
        ])->save();

        return $locked->fresh();
    }

    /**
     * @param  bool  $prefixSourceKey  Prefix keys with boq id so multi-BOQ aggregates never collide.
     */
    private function writeProposedLines(ProjectBudget $locked, Boq $boq, bool $prefixSourceKey = false): void
    {
        $payload = $this->proposedSyncPayload($boq);
        $headByItem = BoqItem::query()
            ->where('boq_id', $boq->id)
            ->pluck('project_cost_head_id', 'id');

        foreach ($payload['lines'] as $row) {
            $itemId = $row['boq_item_id'] ?? null;
            $sourceKey = (string) $row['source_key'];
            if ($prefixSourceKey) {
                $sourceKey = 'boq:'.$boq->id.':'.$sourceKey;
            }
            $line = new ProjectBudgetLine;
            $line->forceFill([
                'cost_head' => $row['cost_head'],
                'project_cost_head_id' => $row['project_cost_head_id'] ?? ($itemId ? $headByItem[$itemId] ?? null : null),
                'boq_item_id' => $row['boq_item_id'],
                'source_type' => $row['source_type'],
                'source_key' => $sourceKey,
                'material_id' => $row['material_id'],
                'material_code' => $row['material_code'],
                'material_name' => $row['material_name'],
                'unit' => $row['unit'],
                'quantity' => $row['quantity'],
                'rate' => $row['rate'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'sources' => $row['sources'],
            ]);
            $line->budget()->associate($locked);
            $line->save();
        }
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
        if (BoqCompanySettings::enabled($boq->company)) {
            return $this->aggregateMaterialsFromCostComponents($boq);
        }

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

    /**
     * One labour budget line per BOQ item with labour_rate > 0 (keeps unit isolation).
     *
     * @return list<array{
     *   source_key: string,
     *   boq_item_id: int,
     *   unit: ?string,
     *   quantity: string,
     *   rate: string,
     *   amount: string,
     *   description: string,
     *   sources: list<array<string, mixed>>
     * }>
     */
    public function labourLines(Boq $boq): array
    {
        if (BoqCompanySettings::enabled($boq->company)) {
            return $this->labourLinesFromCostComponents($boq);
        }

        $items = BoqItem::query()
            ->where('boq_id', $boq->id)
            ->with('unit:id,symbol,name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($items as $item) {
            $rate = Decimal::of((string) ($item->labour_rate ?? '0'));
            if (! $rate->isPositive()) {
                continue;
            }

            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $labourRate = $rate->toRate();
            $amount = Decimal::of($boqQty)->times($labourRate)->toMoney();
            $unit = $item->unit?->symbol ?: $item->unit?->name;
            $label = trim(($item->item_code ? $item->item_code.' ' : '').$item->name);

            $result[] = [
                'source_key' => 'labour:'.($item->line_uid ?: (string) $item->id),
                'boq_item_id' => $item->id,
                'unit' => $unit,
                'quantity' => $boqQty,
                'rate' => $labourRate,
                'amount' => $amount,
                'description' => mb_substr($label !== '' ? $label : 'Labour', 0, 255),
                'sources' => [[
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'boq_item_id' => $item->id,
                    'boq_line_uid' => $item->line_uid,
                    'item_code' => $item->item_code,
                    'item_name' => $item->name,
                    'boq_quantity' => $boqQty,
                    'labour_rate' => $labourRate,
                    'unit' => $unit,
                    'amount' => $amount,
                    'source' => 'boq_legacy_labour_rate',
                ]],
            ];
        }

        return $result;
    }

    /**
     * Full proposed sync rows for a BOQ (materials, labour, equipment, other, BBS steel).
     *
     * @return array{lines: list<array<string, mixed>>, reconciliation: array<string, string>}
     */
    public function proposedSyncPayload(Boq $boq): array
    {
        $materials = $this->aggregateMaterials($boq);
        $labour = $this->labourLines($boq);
        $equipment = BoqCompanySettings::enabled($boq->company) ? $this->componentLines($boq, CostComponentType::Equipment) : [];
        $other = BoqCompanySettings::enabled($boq->company)
            ? [
                ...$this->componentLines($boq, CostComponentType::Other),
                ...$this->componentLines($boq, CostComponentType::Subcontract),
            ]
            : [];

        $lines = [];
        foreach ($materials as $group) {
            $lines[] = [
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
            ];
        }
        foreach ($labour as $group) {
            $lines[] = [
                'cost_head' => CostHead::Labour->value,
                'boq_item_id' => $group['boq_item_id'],
                'source_type' => BudgetLineSource::BoqLabour->value,
                'source_key' => $group['source_key'],
                'material_id' => null,
                'material_code' => null,
                'material_name' => null,
                'unit' => $group['unit'],
                'quantity' => $group['quantity'],
                'rate' => $group['rate'],
                'description' => $group['description'],
                'amount' => $group['amount'],
                'sources' => $group['sources'],
            ];
        }
        foreach ($equipment as $group) {
            $lines[] = [
                'cost_head' => CostHead::Equipment->value,
                'boq_item_id' => $group['boq_item_id'],
                'source_type' => BudgetLineSource::BoqEquipment->value,
                'source_key' => $group['source_key'],
                'material_id' => null,
                'material_code' => null,
                'material_name' => null,
                'unit' => $group['unit'],
                'quantity' => $group['quantity'],
                'rate' => $group['rate'],
                'description' => $group['description'],
                'amount' => $group['amount'],
                'sources' => $group['sources'],
            ];
        }
        foreach ($other as $group) {
            $head = ($group['component_type'] ?? '') === CostComponentType::Subcontract->value
                ? CostHead::Subcontract->value
                : CostHead::Other->value;
            $lines[] = [
                'cost_head' => $head,
                'boq_item_id' => $group['boq_item_id'],
                'source_type' => BudgetLineSource::BoqOther->value,
                'source_key' => $group['source_key'],
                'material_id' => null,
                'material_code' => null,
                'material_name' => null,
                'unit' => $group['unit'],
                'quantity' => $group['quantity'],
                'rate' => $group['rate'],
                'description' => $group['description'],
                'amount' => $group['amount'],
                'sources' => $group['sources'],
            ];
        }

        return [
            'lines' => $lines,
            'reconciliation' => $this->reconciliationTotals($lines),
        ];
    }

    /**
     * Preview sync against an existing draft budget (creates/updates/removes by source_key).
     *
     * @return array<string, mixed>
     */
    public function preview(Project $project, Boq $boq, ?ProjectBudget $budget = null): array
    {
        $budget ??= ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Draft)
            ->orderByDesc('version')
            ->first();

        $proposed = $this->proposedSyncPayload($boq);
        $existing = $budget
            ? $budget->lines()->whereIn('source_type', BudgetLineSource::syncOwnedValues())->get()->keyBy('source_key')
            : collect();

        $proposedByKey = collect($proposed['lines'])->keyBy('source_key');
        $create = [];
        $update = [];
        $unchanged = [];
        foreach ($proposed['lines'] as $row) {
            $current = $existing->get($row['source_key']);
            if ($current === null) {
                $create[] = $row;

                continue;
            }
            $same = (string) $current->quantity === (string) $row['quantity']
                && (string) $current->rate === (string) $row['rate']
                && (string) $current->amount === (string) $row['amount']
                && (string) $current->description === (string) $row['description'];
            if ($same) {
                $unchanged[] = $row;
            } else {
                $update[] = ['from' => $current, 'to' => $row];
            }
        }

        $remove = $existing->keys()->diff($proposedByKey->keys())->values()->all();
        $before = $budget
            ? Decimal::sum($existing->pluck('amount'))->toMoney()
            : '0.00';
        $after = $proposed['reconciliation']['grand'];

        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Approved)
            ->first();

        return [
            'boq_id' => $boq->id,
            'boq_label' => "{$boq->boq_number} v{$boq->version}",
            'budget_id' => $budget?->id,
            'budget_version' => $budget?->version,
            'budget_type' => BudgetType::BoqEstimate->value,
            'blocked_by_approved' => $budget === null && $approved !== null,
            'creates' => count($create),
            'updates' => count($update),
            'unchanged' => count($unchanged),
            'removes' => count($remove),
            'total_before' => $before,
            'total_after' => $after,
            'reconciliation' => $proposed['reconciliation'],
            'create_rows' => $create,
            'update_rows' => $update,
            'remove_keys' => $remove,
        ];
    }

    /**
     * BOQ Estimate publication status for UI cards (replaces manual sync status).
     *
     * @return array{
     *   status: string,
     *   label: string,
     *   budget_version: ?int,
     *   materials_sync_pending: bool,
     *   last_synced_at: ?string,
     *   last_synced_by: ?array{id: int, name: string},
     *   source_boq_id: ?int,
     *   source_boq_version: ?int,
     *   source_internal_cost: ?string,
     *   variance: ?string
     * }
     */
    public function syncStatus(Project $project, ?Boq $boq = null): array
    {
        $boq ??= $project->boqs()->where('is_current', true)->orderByDesc('version')->first();
        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Approved)
            ->first();

        $empty = [
            'status' => 'pending_approval',
            'label' => 'Pending BOQ Approval',
            'budget_version' => null,
            'budget_type' => BudgetType::BoqEstimate->value,
            'materials_sync_pending' => false,
            'last_synced_at' => null,
            'last_synced_by' => null,
            'source_boq_id' => null,
            'source_boq_version' => null,
            'source_internal_cost' => null,
            'variance' => null,
        ];

        if ($boq === null) {
            return $empty;
        }

        $syncedBy = function (?ProjectBudget $budget): ?array {
            if ($budget?->approved_by === null && $budget?->updated_by === null) {
                return null;
            }
            $userId = $budget->approved_by ?? $budget->updated_by;
            $user = User::query()->find($userId);

            return $user ? ['id' => (int) $user->id, 'name' => (string) $user->name] : null;
        };

        if ($boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            if ($approved !== null && (int) $approved->boq_id === (int) $boq->id) {
                return [
                    'status' => 'superseded',
                    'label' => 'Superseded',
                    'budget_version' => $approved->version,
                    'budget_type' => BudgetType::BoqEstimate->value,
                    'materials_sync_pending' => false,
                    'last_synced_at' => $approved->materials_synced_at?->toIso8601String()
                        ?? $approved->approved_at?->toIso8601String(),
                    'last_synced_by' => $syncedBy($approved),
                    'source_boq_id' => $approved->boq_id,
                    'source_boq_version' => $boq->version,
                    'source_internal_cost' => null,
                    'variance' => null,
                ];
            }

            return $empty;
        }

        $forThisBoq = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('boq_id', $boq->id)
            ->where('status', BudgetStatus::Approved)
            ->first();

        if ($forThisBoq === null) {
            return [
                ...$empty,
                'status' => 'pending_approval',
                'label' => $approved === null ? 'Pending BOQ Approval' : 'Estimate Pending Publish',
                'budget_version' => $approved?->version,
            ];
        }

        $proposed = $this->proposedSyncPayload($boq);
        $generated = $forThisBoq->lines()->whereIn('source_type', BudgetLineSource::syncOwnedValues())->get();
        $budgetTotal = Decimal::sum($generated->pluck('amount'))->toMoney();
        $variance = Decimal::of($budgetTotal)->minus($proposed['reconciliation']['grand'])->toMoney();

        $isCurrentEstimate = $approved !== null && (int) $approved->id === (int) $forThisBoq->id;

        return [
            'status' => $isCurrentEstimate ? 'current' : 'superseded',
            'label' => $isCurrentEstimate ? 'Current' : 'Superseded',
            'budget_version' => $forThisBoq->version,
            'budget_type' => BudgetType::BoqEstimate->value,
            'materials_sync_pending' => false,
            'last_synced_at' => $forThisBoq->materials_synced_at?->toIso8601String()
                ?? $forThisBoq->approved_at?->toIso8601String(),
            'last_synced_by' => $syncedBy($forThisBoq),
            'source_boq_id' => $forThisBoq->boq_id,
            'source_boq_version' => $boq->version,
            'source_internal_cost' => $proposed['reconciliation']['grand'],
            'variance' => $variance,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{material: string, labour: string, equipment: string, other: string, grand: string}
     */
    public function reconciliationTotals(array $lines): array
    {
        $material = Decimal::zero();
        $labour = Decimal::zero();
        $equipment = Decimal::zero();
        $other = Decimal::zero();
        foreach ($lines as $line) {
            $amount = Decimal::of((string) $line['amount']);
            $type = $line['source_type'] ?? '';
            if ($type === BudgetLineSource::BoqMaterial->value) {
                $material = $material->plus($amount);
            } elseif ($type === BudgetLineSource::BoqLabour->value) {
                $labour = $labour->plus($amount);
            } elseif ($type === BudgetLineSource::BoqEquipment->value) {
                $equipment = $equipment->plus($amount);
            } else {
                $other = $other->plus($amount);
            }
        }

        return [
            'material' => $material->toMoney(),
            'labour' => $labour->toMoney(),
            'equipment' => $equipment->toMoney(),
            'other' => $other->toMoney(),
            'grand' => $material->plus($labour)->plus($equipment)->plus($other)->toMoney(),
        ];
    }

    /**
     * Advanced BOQ: material budget from cost-build material components + approved BBS steel.
     * Legacy `boq_item_material_costs` are ignored.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregateMaterialsFromCostComponents(Boq $boq): array
    {
        $bbsItemIds = $this->approvedBbsItemIds($boq);

        $components = BoqCostComponent::query()
            ->where('boq_id', $boq->id)
            ->where('component_type', CostComponentType::Material)
            ->where('is_active', true)
            ->with(['item:id,line_uid,item_code,name,quantity'])
            ->orderBy('boq_item_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($components as $component) {
            $item = $component->item;
            if ($item === null) {
                continue;
            }

            // BBS is authoritative for steel — skip steel-like material components on BBS items.
            if (isset($bbsItemIds[(int) $item->id]) && $this->looksLikeSteel((string) $component->description)) {
                continue;
            }

            $perUnitAmount = Decimal::of((string) $component->amount_per_boq_unit);
            if (! $perUnitAmount->isPositive() && ! Decimal::of((string) $component->consumption_qty)->isPositive()) {
                continue;
            }

            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $wastage = Decimal::of((string) ($component->wastage_percent ?? '0'));
            $factor = Decimal::of('1')->plus($wastage->dividedBy('100'));
            $netQty = Decimal::of((string) $component->consumption_qty)->times($factor)->times($boqQty)->toQuantity();
            $rate = Decimal::of((string) $component->rate)->toRate();
            $amount = $perUnitAmount->isPositive()
                ? Decimal::of($perUnitAmount)->times($boqQty)->toMoney()
                : Decimal::of($netQty)->times($rate)->toMoney();

            $unit = trim((string) ($component->consumption_unit ?: 'Nos'));
            $materialName = trim((string) $component->description);
            if ($materialName === '') {
                continue;
            }

            $key = 'adv-mat:'.$this->aggregationKey(null, $materialName, $unit);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'source_key' => $key,
                    'material_id' => null,
                    'material_code' => null,
                    'material_name' => $materialName,
                    'unit' => $unit,
                    'quantity' => Decimal::zero(),
                    'amount' => Decimal::zero(),
                    'sources' => [],
                ];
            }

            $groups[$key]['quantity'] = Decimal::of($groups[$key]['quantity'])->plus($netQty);
            $groups[$key]['amount'] = Decimal::of($groups[$key]['amount'])->plus($amount);
            $groups[$key]['sources'][] = [
                'boq_id' => $boq->id,
                'boq_number' => $boq->boq_number,
                'boq_version' => $boq->version,
                'boq_item_id' => $item->id,
                'boq_line_uid' => $item->line_uid,
                'item_code' => $item->item_code,
                'item_name' => $item->name,
                'boq_quantity' => $boqQty,
                'component_id' => $component->id,
                'wastage_percent' => $wastage->toString(),
                'net_required_qty' => $netQty,
                'calculated_quantity' => $netQty,
                'unit' => $unit,
                'rate' => $rate,
                'amount' => $amount,
                'source' => 'advanced_boq_material',
                'source_type' => 'advanced_boq_material',
            ];
        }

        foreach ($this->bbsSteelGroups($boq) as $key => $group) {
            if (! isset($groups[$key])) {
                $groups[$key] = $group;
            } else {
                $groups[$key]['quantity'] = Decimal::of($groups[$key]['quantity'])->plus($group['quantity']);
                $groups[$key]['amount'] = Decimal::of($groups[$key]['amount'])->plus($group['amount']);
                $groups[$key]['sources'] = [...$groups[$key]['sources'], ...$group['sources']];
            }
        }

        $result = [];
        foreach ($groups as $group) {
            $qty = Decimal::of($group['quantity'])->toQuantity();
            $totalAmount = Decimal::of($group['amount'])->toMoney();
            $weightedRate = Decimal::of($qty)->isZero()
                ? '0.0000'
                : Decimal::of($totalAmount)->dividedBy($qty)->toRate();

            $result[] = [
                'source_key' => $group['source_key'],
                'material_id' => $group['material_id'],
                'material_code' => $group['material_code'],
                'material_name' => $group['material_name'],
                'unit' => $group['unit'],
                'quantity' => $qty,
                'rate' => $weightedRate,
                'amount' => $totalAmount,
                'description' => mb_substr($group['material_name'], 0, 255),
                'sources' => $group['sources'],
            ];
        }

        usort($result, fn (array $a, array $b) => strcasecmp($a['material_name'], $b['material_name']));

        return $result;
    }

    /**
     * Advanced BOQ: labour budget from cost-build labour components (not flat labour_rate).
     *
     * @return list<array<string, mixed>>
     */
    private function labourLinesFromCostComponents(Boq $boq): array
    {
        return $this->componentLines($boq, CostComponentType::Labour);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function componentLines(Boq $boq, CostComponentType $type): array
    {
        $components = BoqCostComponent::query()
            ->where('boq_id', $boq->id)
            ->where('component_type', $type)
            ->where('is_active', true)
            ->with(['item:id,line_uid,item_code,name,quantity,unit_id', 'item.unit:id,symbol,name'])
            ->orderBy('boq_item_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $prefix = match ($type) {
            CostComponentType::Labour => 'adv-lab',
            CostComponentType::Equipment => 'adv-eq',
            CostComponentType::Subcontract => 'adv-sub',
            default => 'adv-oth',
        };
        $sourceTag = match ($type) {
            CostComponentType::Labour => 'advanced_boq_labour',
            CostComponentType::Equipment => 'advanced_boq_equipment',
            default => 'advanced_boq_other',
        };

        $result = [];
        foreach ($components as $component) {
            $item = $component->item;
            if ($item === null) {
                continue;
            }

            $perUnit = Decimal::of((string) $component->amount_per_boq_unit);
            $rate = Decimal::of((string) $component->rate);
            if (! $perUnit->isPositive() && ! $rate->isPositive() && ! Decimal::of((string) $component->consumption_qty)->isPositive()) {
                continue;
            }

            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $wastage = Decimal::of((string) ($component->wastage_percent ?? '0'));
            $factor = Decimal::of('1')->plus($wastage->dividedBy('100'));
            $qty = Decimal::of((string) ($component->consumption_qty ?: '1'))->times($factor)->times($boqQty)->toQuantity();
            $lineRate = $rate->isPositive() ? $rate->toRate() : ($perUnit->isPositive() ? $perUnit->toRate() : '0.0000');
            $amount = $perUnit->isPositive()
                ? Decimal::of($perUnit)->times($boqQty)->toMoney()
                : Decimal::of($qty)->times($lineRate)->toMoney();
            $unit = $component->consumption_unit
                ?: ($item->unit?->symbol ?: $item->unit?->name);
            $label = trim($component->description !== ''
                ? $component->description
                : (($item->item_code ? $item->item_code.' ' : '').$item->name));

            $result[] = [
                'source_key' => $prefix.':'.($item->line_uid ?: $item->id).':'.$component->id,
                'boq_item_id' => $item->id,
                'component_type' => $type->value,
                'unit' => $unit,
                'quantity' => $qty,
                'rate' => $lineRate,
                'amount' => $amount,
                'description' => mb_substr($label !== '' ? $label : $type->label(), 0, 255),
                'sources' => [[
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'boq_item_id' => $item->id,
                    'boq_line_uid' => $item->line_uid,
                    'component_id' => $component->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->name,
                    'boq_quantity' => $boqQty,
                    'quantity' => $qty,
                    'rate' => $lineRate,
                    'unit' => $unit,
                    'amount' => $amount,
                    'source' => $sourceTag,
                    'source_type' => $sourceTag,
                ]],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, true>
     */
    private function approvedBbsItemIds(Boq $boq): array
    {
        return BoqBbs::query()
            ->where('boq_id', $boq->id)
            ->where('status', BbsStatus::Approved)
            ->pluck('boq_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bbsSteelGroups(Boq $boq): array
    {
        $bbsList = BoqBbs::query()
            ->where('boq_id', $boq->id)
            ->where('status', BbsStatus::Approved)
            ->with([
                'lines',
                'steelMaps.material:id,code,name,standard_rate',
                'item:id,line_uid,item_code,name',
            ])
            ->get();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($bbsList as $bbs) {
            $item = $bbs->item;
            if ($item === null) {
                continue;
            }

            $steelRateComponent = BoqCostComponent::query()
                ->where('boq_item_id', $item->id)
                ->where('component_type', CostComponentType::Material)
                ->where('is_active', true)
                ->get()
                ->first(fn (BoqCostComponent $c) => $this->looksLikeSteel((string) $c->description));

            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            foreach ($summary as $row) {
                $map = $maps->get($row['diameter_mm']);
                $materialId = $map?->material_id !== null ? (int) $map->material_id : null;
                $materialCode = $map?->material?->code;
                $materialName = $map?->material?->name
                    ?? ('Steel '.$row['diameter_mm'].' mm');
                $unit = 'Kg';
                $netQty = Decimal::of($row['required_weight'])->toQuantity();

                $rate = '0.0000';
                if ($steelRateComponent !== null) {
                    $compUnit = mb_strtolower(trim((string) ($steelRateComponent->consumption_unit ?: '')));
                    $compRate = Decimal::of((string) $steelRateComponent->rate);
                    if (in_array($compUnit, ['mt', 'ton', 'tonne', 't'], true)) {
                        $unit = 'MT';
                        $netQty = Decimal::of($netQty)->dividedBy('1000', Decimal::QTY_SCALE)->toQuantity();
                        $rate = $compRate->toRate();
                    } elseif ($compUnit === 'kg' || $compUnit === '') {
                        $rate = $compRate->toRate();
                    } else {
                        $rate = $compRate->toRate();
                    }
                } elseif ($map?->material?->standard_rate !== null
                    && Decimal::of((string) $map->material->standard_rate)->isPositive()) {
                    $rate = Decimal::of((string) $map->material->standard_rate)->toRate();
                }

                $amount = Decimal::of($netQty)->times($rate)->toMoney();
                $key = 'adv-bbs:'.$this->aggregationKey($materialId, $materialName, $unit);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'source_key' => $key,
                        'material_id' => $materialId,
                        'material_code' => $materialCode,
                        'material_name' => $materialName,
                        'unit' => $unit,
                        'quantity' => Decimal::zero(),
                        'amount' => Decimal::zero(),
                        'sources' => [],
                    ];
                }

                $groups[$key]['quantity'] = Decimal::of($groups[$key]['quantity'])->plus($netQty);
                $groups[$key]['amount'] = Decimal::of($groups[$key]['amount'])->plus($amount);
                $groups[$key]['sources'][] = [
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'boq_item_id' => $item->id,
                    'boq_line_uid' => $item->line_uid,
                    'bbs_id' => $bbs->id,
                    'diameter_mm' => $row['diameter_mm'],
                    'theoretical_weight' => $row['theoretical_weight'],
                    'wastage_weight' => $row['wastage_weight'],
                    'net_required_qty' => $netQty,
                    'unit' => $unit,
                    'rate' => $rate,
                    'amount' => $amount,
                    'source' => 'advanced_boq_bbs_steel',
                    'source_type' => 'advanced_boq_bbs_steel',
                ];
            }
        }

        return $groups;
    }

    private function looksLikeSteel(string $description): bool
    {
        $text = mb_strtolower($description);

        return (bool) preg_match('/\b(steel|tmt|rebar|reinforcement|tor\s*steel)\b/i', $text);
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
