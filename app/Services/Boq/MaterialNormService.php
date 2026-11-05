<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\MaterialRecipeStatus;
use App\Models\Boq\MaterialRecipeTemplate;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMaterialNorm;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Integrations\TallySyncRecord;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Material;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BOQ material norms (planned consumption). Estimate / planning only — no stock or ledger posts.
 */
class MaterialNormService
{
    public function __construct(private readonly EffectiveBoqQuantityService $effectiveQty) {}

    /**
     * @return array{
     *   norms: list<array<string, mixed>>,
     *   locked: bool,
     *   boq_qty: string,
     *   boq_unit: ?string,
     *   cost_materials: list<array<string, mixed>>,
     *   recipes: list<array<string, mixed>>
     * }
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);
        $effectiveQty = $this->effectiveQty->forItem($item);
        $norms = BoqMaterialNorm::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->with(['material:id,code,name,unit_id,standard_rate', 'material.unit:id,symbol'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $rows = $norms->map(fn (BoqMaterialNorm $n) => $this->normPayload($n, $effectiveQty))->all();

        $costMaterials = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->where('component_type', CostComponentType::Material)
            ->whereNotNull('source_id')
            ->orderBy('sequence')
            ->get(['id', 'source_id', 'description', 'consumption_qty', 'consumption_unit', 'wastage_percent'])
            ->map(fn (BoqCostComponent $c) => [
                'cost_component_id' => $c->id,
                'material_id' => $c->source_id,
                'description' => $c->description,
                'consumption_qty' => (string) $c->consumption_qty,
                'consumption_unit' => $c->consumption_unit,
                'wastage_percent' => $c->wastage_percent !== null ? (string) $c->wastage_percent : '0.0000',
            ])->all();

        $recipes = MaterialRecipeTemplate::query()
            ->where('status', MaterialRecipeStatus::Active)
            ->with(['lines' => fn ($q) => $q->orderBy('sequence')])
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(fn (MaterialRecipeTemplate $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'version' => $r->version,
                'project_id' => $r->project_id,
                'line_count' => $r->lines->count(),
            ])->all();

        return [
            'norms' => $rows,
            'locked' => ! $item->boq->isEditable(),
            'boq_qty' => $effectiveQty,
            'boq_unit' => $item->unit?->symbol,
            'cost_materials' => $costMaterials,
            'recipes' => $recipes,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{payload: array<string, mixed>, side_effects: array<string, int>}
     */
    public function saveNorms(BoqItem $item, array $rows, User $user): array
    {
        $item->loadMissing('boq');
        $boq = $item->boq;
        $boq->assertEditable();

        $sideBefore = $this->sideEffectCounts($boq->project_id);

        $payload = DB::transaction(function () use ($item, $rows, $user, $boq) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            $lockedBoq->assertEditable();

            $kept = [];
            $sequence = 0;
            $seen = [];

            foreach (array_values($rows) as $i => $row) {
                $materialId = (int) ($row['material_id'] ?? 0);
                $material = Material::query()->with('unit:id,symbol')->whereKey($materialId)
                    ->where('company_id', $lockedBoq->company_id)
                    ->where('is_active', true)
                    ->first()
                    ?? throw ValidationException::withMessages(["norms.{$i}.material_id" => 'Select an active material from this company.']);

                $unit = trim((string) ($row['consumption_unit'] ?? $material->unit?->symbol ?? ''));
                if ($unit === '') {
                    throw ValidationException::withMessages(["norms.{$i}.consumption_unit" => 'Consumption unit is required.']);
                }

                $dupKey = $materialId.'|'.mb_strtolower($unit);
                if (isset($seen[$dupKey])) {
                    throw ValidationException::withMessages(["norms.{$i}.material_id" => 'Duplicate material/unit norm on this BOQ item.']);
                }
                $seen[$dupKey] = true;

                $calc = MaterialNormCalculator::line([
                    'boq_qty' => (string) $lockedItem->quantity,
                    'consumption_qty' => $row['consumption_qty'] ?? '0',
                    'wastage_percent' => $row['wastage_percent'] ?? '0',
                ]);

                $id = isset($row['id']) ? (int) $row['id'] : null;
                $norm = $id
                    ? BoqMaterialNorm::query()->whereKey($id)->where('boq_item_id', $lockedItem->id)->firstOr(
                        fn () => throw ValidationException::withMessages(["norms.{$i}.id" => 'Norm not found on this item.'])
                    )
                    : new BoqMaterialNorm;

                $wasNew = ! $norm->exists;
                $norm->forceFill([
                    'company_id' => $lockedBoq->company_id,
                    'project_id' => $lockedBoq->project_id,
                    'boq_id' => $lockedBoq->id,
                    'boq_item_id' => $lockedItem->id,
                    'boq_line_uid' => $lockedItem->line_uid,
                    'material_id' => $material->id,
                    'consumption_qty' => $calc['consumption_qty'],
                    'consumption_unit' => $unit,
                    'wastage_percent' => $calc['wastage_percent'],
                    'notes' => isset($row['notes']) ? (trim((string) $row['notes']) ?: null) : $norm->notes,
                    'sequence' => $sequence++,
                    'is_active' => true,
                    'updated_by' => $user->id,
                    'created_by' => $norm->created_by ?? $user->id,
                ])->save();

                $kept[] = $norm->id;

                if ($wasNew) {
                    $norm->writeAudit('material_norm_added', null, [
                        'material_id' => $material->id,
                        'consumption_qty' => $calc['consumption_qty'],
                        'wastage_percent' => $calc['wastage_percent'],
                    ]);
                }
            }

            $toRemove = BoqMaterialNorm::query()
                ->where('boq_item_id', $lockedItem->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept), fn ($q) => $q)
                ->get();

            foreach ($toRemove as $dead) {
                $dead->writeAudit('material_norm_removed', [
                    'material_id' => $dead->material_id,
                    'consumption_qty' => (string) $dead->consumption_qty,
                ], null);
                $dead->delete();
            }

            return $this->workspacePayload($lockedItem->fresh(['boq', 'unit']));
        });

        $sideAfter = $this->sideEffectCounts($boq->project_id);

        return [
            'payload' => $payload,
            'side_effects' => [
                'stock_before' => $sideBefore['stock'],
                'stock_after' => $sideAfter['stock'],
                'ledger_before' => $sideBefore['ledger'],
                'ledger_after' => $sideAfter['ledger'],
                'mr_before' => $sideBefore['mr'],
                'mr_after' => $sideAfter['mr'],
                'po_before' => $sideBefore['po'],
                'po_after' => $sideAfter['po'],
                'grn_before' => $sideBefore['grn'],
                'grn_after' => $sideAfter['grn'],
                'tally_before' => $sideBefore['tally'],
                'tally_after' => $sideAfter['tally'],
            ],
        ];
    }

    /**
     * Copy Stage 3 material cost components into norms (consumption + wastage once — no double apply).
     *
     * @param  list<int>  $costComponentIds
     */
    public function importFromCostBuildUp(BoqItem $item, array $costComponentIds, User $user): array
    {
        $item->loadMissing('boq');
        $item->boq->assertEditable();

        $components = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('component_type', CostComponentType::Material)
            ->whereIn('id', $costComponentIds)
            ->whereNotNull('source_id')
            ->get();

        $existing = BoqMaterialNorm::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->get()
            ->map(fn (BoqMaterialNorm $n) => [
                'id' => $n->id,
                'material_id' => $n->material_id,
                'consumption_qty' => (string) $n->consumption_qty,
                'consumption_unit' => $n->consumption_unit,
                'wastage_percent' => (string) $n->wastage_percent,
                'notes' => $n->notes,
            ])->all();

        foreach ($components as $c) {
            $unit = $c->consumption_unit ?: 'Nos';
            $match = collect($existing)->first(
                fn ($row) => (int) $row['material_id'] === (int) $c->source_id
                    && mb_strtolower((string) $row['consumption_unit']) === mb_strtolower($unit)
            );
            if ($match) {
                continue;
            }
            $existing[] = [
                'material_id' => $c->source_id,
                'consumption_qty' => (string) $c->consumption_qty,
                'consumption_unit' => $unit,
                'wastage_percent' => $c->wastage_percent !== null ? (string) $c->wastage_percent : '0',
                'notes' => 'Copied from Cost Build-Up (base consumption; wastage not re-applied).',
            ];
        }

        return $this->saveNorms($item, $existing, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function normPayload(BoqMaterialNorm $n, string $boqQty): array
    {
        $calc = MaterialNormCalculator::line([
            'boq_qty' => $boqQty,
            'consumption_qty' => (string) $n->consumption_qty,
            'wastage_percent' => (string) $n->wastage_percent,
        ]);

        return [
            'id' => $n->id,
            'material_id' => $n->material_id,
            'material' => $n->material ? [
                'id' => $n->material->id,
                'code' => $n->material->code,
                'name' => $n->material->name,
                'unit' => $n->material->unit?->symbol,
                'standard_rate' => $n->material->standard_rate !== null ? (string) $n->material->standard_rate : null,
            ] : null,
            'consumption_qty' => $calc['consumption_qty'],
            'consumption_unit' => $n->consumption_unit,
            'wastage_percent' => $calc['wastage_percent'],
            'base_requirement' => $calc['base_requirement'],
            'wastage_qty' => $calc['wastage_qty'],
            'required_qty' => $calc['required_qty'],
            'notes' => $n->notes,
            'recipe_template_id' => $n->recipe_template_id,
            'recipe_version' => $n->recipe_version,
            'recipe_code' => $n->recipe_code,
            'sequence' => $n->sequence,
        ];
    }

    /**
     * @return array{stock: int, ledger: int, mr: int, po: int, grn: int, tally: int}
     */
    private function sideEffectCounts(int $projectId): array
    {
        return [
            'stock' => StockTransaction::query()->where('project_id', $projectId)->count(),
            'ledger' => ProjectCostEntry::query()->where('project_id', $projectId)->count(),
            'mr' => MaterialRequest::query()->where('project_id', $projectId)->count(),
            'po' => PurchaseOrder::query()->where('project_id', $projectId)->count(),
            'grn' => Grn::query()->where('project_id', $projectId)->count(),
            'tally' => class_exists(TallySyncRecord::class)
                ? TallySyncRecord::query()->count()
                : 0,
        ];
    }

    /**
     * BOQs that contribute to project planning requirements.
     */
    public static function contributingBoqQuery(int $projectId)
    {
        return Boq::query()
            ->where('project_id', $projectId)
            ->where('status', '!=', BoqStatus::Revised->value);
    }
}
