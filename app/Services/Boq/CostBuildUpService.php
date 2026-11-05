<?php

namespace App\Services\Boq;

use App\Enums\Boq\CostCalculationBasis;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\CostRateSource;
use App\Models\Boq\CostingRateLibraryEntry;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estimated Cost Build-Up for a BOQ line. Never posts stock, ledger, MR, PO, or Tally.
 */
class CostBuildUpService
{
    public function __construct(
        private readonly BoqService $boqs,
        private readonly UnitConversionService $conversions,
    ) {}

    /**
     * @return array{
     *   components: list<array<string, mixed>>,
     *   subtotals: array<string, string>,
     *   category_totals: array{material: string, labour: string, equipment: string, subcontract: string, other: string, total: string},
     *   summary: array<string, mixed>,
     *   locked: bool
     * }
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing('boq');
        $components = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $rows = $components->map(fn (BoqCostComponent $c) => $this->componentPayload($c))->all();
        $categoryTotals = $this->categoryTotals($components);
        $subtotals = [
            CostComponentType::Material->value => $categoryTotals['material'],
            CostComponentType::Labour->value => $categoryTotals['labour'],
            CostComponentType::Equipment->value => $categoryTotals['equipment'],
            CostComponentType::Subcontract->value => $categoryTotals['subcontract'],
            CostComponentType::Other->value => $categoryTotals['other'],
        ];
        $costRate = $categoryTotals['total'];
        $selling = (string) $item->client_rate;
        $effectiveQty = app(\App\Services\Boq\EffectiveBoqQuantityService::class)->forItem($item);
        $summary = CostBuildUpCalculator::summary($effectiveQty, $costRate, $selling);

        return [
            'components' => $rows,
            'subtotals' => $subtotals,
            'category_totals' => $categoryTotals,
            'summary' => $summary,
            'locked' => ! $item->boq->isEditable(),
        ];
    }

    /**
     * Replace active components for an item and sync BOQ cost rates. Estimate only.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{payload: array<string, mixed>, ledger_count_before: int, ledger_count_after: int}
     */
    public function saveComponents(BoqItem $item, array $rows, User $user): array
    {
        $item->loadMissing('boq');
        $boq = $item->boq;
        $boq->assertEditable();

        $ledgerBefore = ProjectCostEntry::query()->where('project_id', $boq->project_id)->count();

        $payload = DB::transaction(function () use ($item, $rows, $user, $boq) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            $lockedBoq->assertEditable();

            $kept = [];
            $sequence = 0;
            $typeTotals = [
                CostComponentType::Material->value => Decimal::zero(),
                CostComponentType::Labour->value => Decimal::zero(),
                CostComponentType::Equipment->value => Decimal::zero(),
                CostComponentType::Subcontract->value => Decimal::zero(),
                CostComponentType::Other->value => Decimal::zero(),
            ];

            $sheetInput = [];
            $meta = [];
            foreach (array_values($rows) as $i => $row) {
                $type = CostComponentType::tryFrom((string) ($row['component_type'] ?? ''))
                    ?? throw ValidationException::withMessages(["components.{$i}.component_type" => 'Invalid component type.']);

                $rateSource = CostRateSource::tryFrom((string) ($row['rate_source'] ?? CostRateSource::Manual->value))
                    ?? CostRateSource::Manual;

                $resolved = $this->resolveSource($lockedBoq, $type, $row, $i, $rateSource);

                $description = trim((string) ($row['description'] ?? $resolved['description'] ?? ''));
                if ($description === '') {
                    throw ValidationException::withMessages(["components.{$i}.description" => 'Description is required.']);
                }

                $basis = CostCalculationBasis::tryFrom((string) ($row['calculation_basis'] ?? CostCalculationBasis::QuantityRate->value))
                    ?? CostCalculationBasis::QuantityRate;

                $consumptionQty = (string) ($row['consumption_qty'] ?? '0');
                $rate = (string) ($row['rate'] ?? $resolved['rate'] ?? '0');
                $conversionSnapshot = null;

                if (! empty($row['convert_from_unit']) && ! empty($row['convert_to_unit']) && $row['convert_to_unit'] === ($row['consumption_unit'] ?? $resolved['unit'])) {
                    $converted = $this->conversions->convert(
                        (int) $lockedBoq->company_id,
                        (int) $lockedBoq->project_id,
                        $resolved['source_id'],
                        (string) $row['convert_from_unit'],
                        (string) $row['convert_to_unit'],
                        $consumptionQty,
                    );
                    $consumptionQty = $converted['result'];
                    $conversionSnapshot = $converted['conversion'] + ['derivation_trace' => $converted['derivation_trace']];
                }

                $sheetInput[$i] = [
                    'component_type' => $type->value,
                    'calculation_basis' => $basis->value,
                    'consumption_qty' => $consumptionQty,
                    'rate' => $rate,
                    'base_rate' => $row['base_rate'] ?? null,
                    'factor' => $row['factor'] ?? null,
                    'factor_reference' => $row['factor_reference'] ?? null,
                    'percent_value' => $row['percent_value'] ?? null,
                    'wastage_percent' => $row['wastage_percent'] ?? '0',
                    'amount_per_boq_unit' => $row['amount_per_boq_unit'] ?? null,
                ];
                $meta[$i] = [
                    'type' => $type,
                    'rate_source' => $rateSource,
                    'resolved' => $resolved,
                    'description' => $description,
                    'consumption_unit' => $row['consumption_unit'] ?? $resolved['unit'] ?? null,
                    'row' => $row,
                    'conversion_snapshot' => $conversionSnapshot,
                ];
            }

            $calculated = CostBuildUpCalculator::calculateSheet($sheetInput);

            foreach ($calculated as $i => $calc) {
                $m = $meta[$i];
                $type = $m['type'];
                $resolved = $m['resolved'];
                $description = $m['description'];
                $rateSource = $m['rate_source'];
                $row = $m['row'];

                $id = isset($row['id']) ? (int) $row['id'] : null;
                $component = $id
                    ? BoqCostComponent::query()
                        ->whereKey($id)
                        ->where('boq_item_id', $lockedItem->id)
                        ->firstOr(fn () => throw ValidationException::withMessages(["components.{$i}.id" => 'Component not found on this item.']))
                    : new BoqCostComponent;

                $wasNew = ! $component->exists;
                $oldAmount = $component->exists ? (string) $component->amount_per_boq_unit : null;

                $component->forceFill([
                    'company_id' => $lockedBoq->company_id,
                    'project_id' => $lockedBoq->project_id,
                    'boq_id' => $lockedBoq->id,
                    'boq_item_id' => $lockedItem->id,
                    'boq_line_uid' => $lockedItem->line_uid,
                    'component_type' => $type,
                    'source_type' => $resolved['source_type'],
                    'source_id' => $resolved['source_id'],
                    'description' => $description,
                    'calculation_basis' => $calc['calculation_basis'],
                    'consumption_qty' => $calc['consumption_qty'],
                    'consumption_unit' => $m['consumption_unit'],
                    'rate' => $calc['rate'],
                    'base_rate' => $calc['base_rate'],
                    'factor' => $calc['factor'],
                    'factor_reference' => $row['factor_reference'] ?? null,
                    'percent_value' => $calc['percent_value'],
                    'calculated_rate' => $calc['calculated_rate'],
                    'derivation_trace' => $calc['derivation_trace'],
                    'rate_library_id' => $resolved['rate_library_id'] ?? null,
                    'conversion_snapshot' => $m['conversion_snapshot'],
                    'wastage_percent' => $calc['wastage_percent'],
                    'amount_per_boq_unit' => $calc['amount_per_boq_unit'],
                    'rate_source' => $rateSource,
                    'sequence' => $sequence++,
                    'is_active' => true,
                    'updated_by' => $user->id,
                    'created_by' => $component->created_by ?? $user->id,
                ])->save();

                $kept[] = $component->id;
                $typeTotals[$type->value] = $typeTotals[$type->value]->plus($calc['amount_per_boq_unit']);

                if ($wasNew) {
                    $component->writeAudit('cost_component_added', null, [
                        'description' => $description,
                        'amount_per_boq_unit' => $calc['amount_per_boq_unit'],
                    ]);
                } elseif ($oldAmount !== $calc['amount_per_boq_unit']) {
                    $component->writeAudit('cost_component_updated', [
                        'amount_per_boq_unit' => $oldAmount,
                    ], [
                        'amount_per_boq_unit' => $calc['amount_per_boq_unit'],
                    ]);
                }
            }

            $toRemove = BoqCostComponent::query()
                ->where('boq_item_id', $lockedItem->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept), fn ($q) => $q)
                ->get();

            foreach ($toRemove as $dead) {
                $dead->writeAudit('cost_component_removed', [
                    'description' => $dead->description,
                    'amount_per_boq_unit' => (string) $dead->amount_per_boq_unit,
                ], null);
                $dead->delete();
            }

            $oldCostRate = (string) $lockedItem->cost_rate;
            $this->syncItemRates($lockedItem, $lockedBoq, $typeTotals, $user);

            $fresh = $lockedItem->fresh(['boq']);
            $newCostRate = (string) $fresh->cost_rate;
            if ($oldCostRate !== $newCostRate) {
                $fresh->boq->writeAudit('cost_rate_changed', [
                    'boq_item_id' => $fresh->id,
                    'line_uid' => $fresh->line_uid,
                    'cost_rate' => $oldCostRate,
                ], [
                    'boq_item_id' => $fresh->id,
                    'line_uid' => $fresh->line_uid,
                    'cost_rate' => $newCostRate,
                ]);
            }

            return $this->workspacePayload($fresh);
        });

        $ledgerAfter = ProjectCostEntry::query()->where('project_id', $boq->project_id)->count();

        return [
            'payload' => $payload,
            'ledger_count_before' => $ledgerBefore,
            'ledger_count_after' => $ledgerAfter,
        ];
    }

    /**
     * Snapshot a rate from material master / latest PO / labour master when requested.
     *
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    public function suggestRate(Boq $boq, CostComponentType $type, CostRateSource $source, ?int $sourceId): array
    {
        return match ([$type, $source]) {
            [CostComponentType::Material, CostRateSource::MaterialMaster] => $this->materialMasterRate($boq, $sourceId),
            [CostComponentType::Material, CostRateSource::LatestPo] => $this->latestPoRate($boq, $sourceId),
            [CostComponentType::Labour, CostRateSource::LabourMaster] => $this->labourMasterRate($boq, $sourceId),
            [CostComponentType::Equipment, CostRateSource::EquipmentMaster] => $this->equipmentMasterMeta($boq, $sourceId),
            [CostComponentType::Material, CostRateSource::RateLibrary],
            [CostComponentType::Labour, CostRateSource::RateLibrary],
            [CostComponentType::Equipment, CostRateSource::RateLibrary],
            [CostComponentType::Subcontract, CostRateSource::RateLibrary],
            [CostComponentType::Other, CostRateSource::RateLibrary] => $this->rateLibraryMeta($boq, $sourceId),
            default => [
                'rate' => '0.0000',
                'rate_source' => CostRateSource::Manual->value,
                'unit' => null,
                'description' => null,
            ],
        };
    }

    /**
     * Authoritative per-category totals (+ total cost rate) from active component amounts.
     *
     * @param  iterable<BoqCostComponent|array<string, mixed>>  $components
     * @return array{material: string, labour: string, equipment: string, subcontract: string, other: string, total: string}
     */
    public function categoryTotals(iterable $components): array
    {
        return CostBuildUpCalculator::categoryTotals($components);
    }

    /**
     * @param  iterable<BoqCostComponent>  $components
     * @return array<string, string>
     */
    public function subtotals(iterable $components): array
    {
        $totals = $this->categoryTotals($components);

        return [
            CostComponentType::Material->value => $totals['material'],
            CostComponentType::Labour->value => $totals['labour'],
            CostComponentType::Equipment->value => $totals['equipment'],
            CostComponentType::Subcontract->value => $totals['subcontract'],
            CostComponentType::Other->value => $totals['other'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(BoqCostComponent $c): array
    {
        return [
            'id' => $c->id,
            'component_type' => $c->component_type->value,
            'source_type' => $c->source_type,
            'source_id' => $c->source_id,
            'description' => $c->description,
            'consumption_qty' => (string) $c->consumption_qty,
            'consumption_unit' => $c->consumption_unit,
            'rate' => (string) $c->rate,
            'wastage_percent' => $c->wastage_percent !== null ? (string) $c->wastage_percent : '0.0000',
            'amount_per_boq_unit' => (string) $c->amount_per_boq_unit,
            'rate_source' => $c->rate_source?->value ?? CostRateSource::Manual->value,
            'calculation_basis' => $c->calculation_basis?->value ?? CostCalculationBasis::QuantityRate->value,
            'base_rate' => $c->base_rate !== null ? (string) $c->base_rate : null,
            'factor' => $c->factor !== null ? (string) $c->factor : null,
            'factor_reference' => $c->factor_reference,
            'percent_value' => $c->percent_value !== null ? (string) $c->percent_value : null,
            'calculated_rate' => $c->calculated_rate !== null ? (string) $c->calculated_rate : null,
            'derivation_trace' => $c->derivation_trace,
            'rate_library_id' => $c->rate_library_id,
            'conversion_snapshot' => $c->conversion_snapshot,
            'sequence' => $c->sequence,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{source_type: ?string, source_id: ?int, description: ?string, unit: ?string, rate: ?string, rate_library_id: ?int}
     */
    private function resolveSource(Boq $boq, CostComponentType $type, array $row, int $index, CostRateSource $rateSource): array
    {
        if ($rateSource === CostRateSource::RateLibrary) {
            $libraryId = isset($row['rate_library_id']) ? (int) $row['rate_library_id'] : (isset($row['source_id']) ? (int) $row['source_id'] : null);
            if (! $libraryId) {
                throw ValidationException::withMessages(["components.{$index}.rate_library_id" => 'Select a rate library entry.']);
            }
            $meta = $this->rateLibraryMeta($boq, $libraryId);

            return [
                'source_type' => CostingRateLibraryEntry::class,
                'source_id' => $libraryId,
                'description' => $meta['description'],
                'unit' => $meta['unit'],
                'rate' => $meta['rate'],
                'rate_library_id' => $libraryId,
            ];
        }

        $sourceId = isset($row['source_id']) && $row['source_id'] !== null && $row['source_id'] !== ''
            ? (int) $row['source_id']
            : null;

        if ($sourceId === null) {
            return [
                'source_type' => null,
                'source_id' => null,
                'description' => $row['description'] ?? null,
                'unit' => $row['consumption_unit'] ?? null,
                'rate' => $row['rate'] ?? null,
                'rate_library_id' => null,
            ];
        }

        $resolved = match ($type) {
            CostComponentType::Material => $this->assertMaterial($boq, $sourceId, $index),
            CostComponentType::Labour => $this->assertLabour($boq, $sourceId, $index),
            CostComponentType::Equipment => $this->assertEquipment($boq, $sourceId, $index),
            default => [
                'source_type' => null,
                'source_id' => null,
                'description' => $row['description'] ?? null,
                'unit' => $row['consumption_unit'] ?? null,
            ],
        };

        return $resolved + ['rate' => $row['rate'] ?? null, 'rate_library_id' => null];
    }

    /**
     * @return array{source_type: string, source_id: int, description: string, unit: ?string}
     */
    private function assertMaterial(Boq $boq, int $id, int $index): array
    {
        $material = Material::query()->with('unit:id,symbol')->whereKey($id)->where('company_id', $boq->company_id)->first()
            ?? throw ValidationException::withMessages(["components.{$index}.source_id" => 'Material not found in this company.']);

        return [
            'source_type' => Material::class,
            'source_id' => $material->id,
            'description' => $material->name,
            'unit' => $material->unit?->symbol ?? null,
        ];
    }

    /**
     * @return array{source_type: string, source_id: int, description: string, unit: ?string}
     */
    private function assertLabour(Boq $boq, int $id, int $index): array
    {
        $trade = LabourTrade::query()->whereKey($id)->where('company_id', $boq->company_id)->first()
            ?? throw ValidationException::withMessages(["components.{$index}.source_id" => 'Labour category not found in this company.']);

        return [
            'source_type' => LabourTrade::class,
            'source_id' => $trade->id,
            'description' => $trade->name,
            'unit' => 'day',
        ];
    }

    /**
     * @return array{source_type: string, source_id: int, description: string, unit: ?string}
     */
    private function assertEquipment(Boq $boq, int $id, int $index): array
    {
        $type = EquipmentType::query()->whereKey($id)->where('company_id', $boq->company_id)->first()
            ?? throw ValidationException::withMessages(["components.{$index}.source_id" => 'Equipment category not found in this company.']);

        return [
            'source_type' => EquipmentType::class,
            'source_id' => $type->id,
            'description' => $type->name,
            'unit' => 'hour',
        ];
    }

    /**
     * @param  array<string, Decimal>  $typeTotals
     */
    private function syncItemRates(BoqItem $item, Boq $boq, array $typeTotals, User $user): void
    {
        $material = $typeTotals[CostComponentType::Material->value]->toRate();
        $labour = $typeTotals[CostComponentType::Labour->value]->toRate();
        $equipment = $typeTotals[CostComponentType::Equipment->value]->toRate();
        // Other estimated direct costs share the subcontract_rate column (no fifth BOQ rate field).
        $subcontract = $typeTotals[CostComponentType::Subcontract->value]
            ->plus($typeTotals[CostComponentType::Other->value])
            ->toRate();

        $costRate = Decimal::sum([$material, $labour, $equipment, $subcontract])->toRate();
        $clientRate = (string) $item->client_rate;

        $calc = BoqCalculator::line([
            'quantity' => (string) $item->quantity,
            'material_rate' => $material,
            'labour_rate' => $labour,
            'equipment_rate' => $equipment,
            'subcontract_rate' => $subcontract,
            'margin_percent' => BoqCalculator::impliedMargin($costRate, $clientRate),
            'client_rate' => $clientRate,
        ]);

        $item->forceFill($calc)->save();

        $this->boqs->recalculateTotals($boq->fresh());
    }

    /**
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    private function materialMasterRate(Boq $boq, ?int $sourceId): array
    {
        if (! $sourceId) {
            throw ValidationException::withMessages(['source_id' => 'Select a material.']);
        }
        $material = Material::query()->with('unit:id,symbol')->whereKey($sourceId)->where('company_id', $boq->company_id)->firstOrFail();

        return [
            'rate' => Decimal::of((string) ($material->standard_rate ?? '0'))->toRate(),
            'rate_source' => CostRateSource::MaterialMaster->value,
            'unit' => $material->unit?->symbol,
            'description' => $material->name,
        ];
    }

    /**
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    private function latestPoRate(Boq $boq, ?int $sourceId): array
    {
        if (! $sourceId) {
            throw ValidationException::withMessages(['source_id' => 'Select a material.']);
        }
        Material::query()->whereKey($sourceId)->where('company_id', $boq->company_id)->firstOrFail();

        $approved = [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
        ];

        $rate = PurchaseOrderItem::query()
            ->where('material_id', $sourceId)
            ->whereHas('purchaseOrder', fn ($q) => $q
                ->where('company_id', $boq->company_id)
                ->whereIn('status', $approved))
            ->orderByDesc('id')
            ->value('rate');

        if ($rate === null) {
            throw ValidationException::withMessages(['rate' => 'No approved purchase order rate found for this material.']);
        }

        $meta = $this->materialMasterRate($boq, $sourceId);

        return [
            'rate' => Decimal::of((string) $rate)->toRate(),
            'rate_source' => CostRateSource::LatestPo->value,
            'unit' => $meta['unit'],
            'description' => $meta['description'],
        ];
    }

    /**
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    private function labourMasterRate(Boq $boq, ?int $sourceId): array
    {
        if (! $sourceId) {
            throw ValidationException::withMessages(['source_id' => 'Select a labour category.']);
        }
        $trade = LabourTrade::query()->whereKey($sourceId)->where('company_id', $boq->company_id)->firstOrFail();

        return [
            'rate' => Decimal::of((string) ($trade->default_daily_wage ?? '0'))->toRate(),
            'rate_source' => CostRateSource::LabourMaster->value,
            'unit' => 'day',
            'description' => $trade->name,
        ];
    }

    /**
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    private function equipmentMasterMeta(Boq $boq, ?int $sourceId): array
    {
        if (! $sourceId) {
            throw ValidationException::withMessages(['source_id' => 'Select an equipment category.']);
        }
        $type = EquipmentType::query()->whereKey($sourceId)->where('company_id', $boq->company_id)->firstOrFail();

        return [
            'rate' => '0.0000',
            'rate_source' => CostRateSource::EquipmentMaster->value,
            'unit' => 'hour',
            'description' => $type->name,
        ];
    }

    /**
     * @return array{rate: string, rate_source: string, unit: ?string, description: ?string}
     */
    private function rateLibraryMeta(Boq $boq, ?int $entryId): array
    {
        if (! $entryId) {
            throw ValidationException::withMessages(['rate_library_id' => 'Select a rate library entry.']);
        }

        $entry = CostingRateLibraryEntry::query()
            ->whereKey($entryId)
            ->where('company_id', $boq->company_id)
            ->where('is_active', true)
            ->firstOrFail();

        return [
            'rate' => Decimal::of((string) $entry->rate)->toRate(),
            'rate_source' => CostRateSource::RateLibrary->value,
            'unit' => $entry->unit,
            'description' => $entry->description,
        ];
    }
}
