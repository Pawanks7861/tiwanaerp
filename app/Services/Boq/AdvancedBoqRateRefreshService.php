<?php

namespace App\Services\Boq;

use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\CostRateSource;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqItemMaterialCost;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit authorized refresh of Advanced BOQ material/labour rates from masters.
 * Never runs automatically on revision create.
 */
class AdvancedBoqRateRefreshService
{
    public function __construct(private readonly CostBuildUpService $costs) {}

    /**
     * Preview rate changes without writing.
     *
     * @return array{changes: list<array<string, mixed>>, unchanged: int}
     */
    public function preview(BoqItem $item, string $scope = 'material'): array
    {
        $item->loadMissing('boq');
        $item->boq->assertEditable();

        return match ($scope) {
            'material' => $this->materialChanges($item, apply: false),
            'labour' => $this->labourChanges($item, apply: false),
            default => throw ValidationException::withMessages(['scope' => 'Choose material or labour.']),
        };
    }

    /**
     * Apply refreshed rates and recalculate cost build-up amounts.
     *
     * @return array{changes: list<array<string, mixed>>, unchanged: int}
     */
    public function apply(BoqItem $item, string $scope, User $user): array
    {
        $item->loadMissing('boq');
        $item->boq->assertEditable();

        return DB::transaction(function () use ($item, $scope, $user) {
            $locked = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('boq');
            $locked->boq->assertEditable();

            $result = match ($scope) {
                'material' => $this->materialChanges($locked, apply: true, user: $user),
                'labour' => $this->labourChanges($locked, apply: true, user: $user),
                default => throw ValidationException::withMessages(['scope' => 'Choose material or labour.']),
            };

            // Rebuild cost sheet from persisted components so amounts stay authoritative.
            $components = BoqCostComponent::query()
                ->where('boq_item_id', $locked->id)
                ->where('is_active', true)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get();

            if ($components->isNotEmpty()) {
                $rows = $components->map(fn (BoqCostComponent $c) => [
                    'id' => $c->id,
                    'component_type' => $c->component_type?->value,
                    'source_id' => $c->source_id,
                    'description' => $c->description,
                    'consumption_qty' => (string) $c->consumption_qty,
                    'consumption_unit' => $c->consumption_unit,
                    'rate' => (string) $c->rate,
                    'wastage_percent' => (string) ($c->wastage_percent ?? '0'),
                    'rate_source' => $c->rate_source?->value ?? CostRateSource::Manual->value,
                    'calculation_basis' => $c->calculation_basis?->value,
                    'base_rate' => $c->base_rate !== null ? (string) $c->base_rate : null,
                    'factor' => $c->factor !== null ? (string) $c->factor : null,
                    'factor_reference' => $c->factor_reference,
                    'percent_value' => $c->percent_value !== null ? (string) $c->percent_value : null,
                    'amount_per_boq_unit' => (string) $c->amount_per_boq_unit,
                    'rate_library_id' => $c->rate_library_id,
                ])->all();

                $this->costs->saveComponents($locked->fresh(), $rows, $user);
            }

            $locked->boq->writeAudit('rates_refreshed', null, [
                'boq_item_id' => $locked->id,
                'line_uid' => $locked->line_uid,
                'scope' => $scope,
                'changes' => $result['changes'],
            ]);

            return $result;
        });
    }

    /**
     * @return array{changes: list<array<string, mixed>>, unchanged: int}
     */
    private function materialChanges(BoqItem $item, bool $apply, ?User $user = null): array
    {
        $changes = [];
        $unchanged = 0;

        $components = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->where('component_type', CostComponentType::Material->value)
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->get();

        foreach ($components as $component) {
            $material = Material::query()
                ->whereKey($component->source_id)
                ->where('company_id', $item->boq->company_id)
                ->first();
            if ($material === null) {
                continue;
            }
            $old = Decimal::of((string) $component->rate)->toRate();
            $new = Decimal::of((string) ($material->standard_rate ?? '0'))->toRate();
            if (Decimal::of($old)->equals($new)) {
                $unchanged++;

                continue;
            }
            $changes[] = [
                'kind' => 'cost_component',
                'id' => $component->id,
                'description' => $component->description,
                'material_id' => $material->id,
                'from' => $old,
                'to' => $new,
                'rate_source' => CostRateSource::MaterialMaster->value,
            ];
            if ($apply) {
                $component->forceFill([
                    'rate' => $new,
                    'rate_source' => CostRateSource::MaterialMaster,
                    'updated_by' => $user?->id,
                ])->save();
            }
        }

        $simple = BoqItemMaterialCost::query()
            ->where('boq_item_id', $item->id)
            ->whereNotNull('material_id')
            ->orderBy('id')
            ->get();

        foreach ($simple as $row) {
            $material = Material::query()
                ->whereKey($row->material_id)
                ->where('company_id', $item->boq->company_id)
                ->first();
            if ($material === null) {
                continue;
            }
            $old = Decimal::of((string) $row->rate)->toRate();
            $new = Decimal::of((string) ($material->standard_rate ?? '0'))->toRate();
            if (Decimal::of($old)->equals($new)) {
                $unchanged++;

                continue;
            }
            $changes[] = [
                'kind' => 'simple_material',
                'id' => $row->id,
                'description' => $row->material_name,
                'material_id' => $material->id,
                'from' => $old,
                'to' => $new,
                'rate_source' => CostRateSource::MaterialMaster->value,
            ];
            if ($apply) {
                $amount = Decimal::of((string) $row->quantity)->times($new)->round(Decimal::MONEY_SCALE)->toMoney();
                $row->forceFill([
                    'rate' => $new,
                    'amount' => $amount,
                    'updated_by' => $user?->id,
                ])->save();
            }
        }

        return ['changes' => $changes, 'unchanged' => $unchanged];
    }

    /**
     * @return array{changes: list<array<string, mixed>>, unchanged: int}
     */
    private function labourChanges(BoqItem $item, bool $apply, ?User $user = null): array
    {
        $changes = [];
        $unchanged = 0;

        $components = BoqCostComponent::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->where('component_type', CostComponentType::Labour->value)
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->get();

        foreach ($components as $component) {
            $trade = LabourTrade::query()
                ->whereKey($component->source_id)
                ->where('company_id', $item->boq->company_id)
                ->first();
            if ($trade === null) {
                continue;
            }
            $old = Decimal::of((string) $component->rate)->toRate();
            $new = Decimal::of((string) ($trade->default_daily_wage ?? '0'))->toRate();
            if (Decimal::of($old)->equals($new)) {
                $unchanged++;

                continue;
            }
            $changes[] = [
                'kind' => 'cost_component',
                'id' => $component->id,
                'description' => $component->description,
                'labour_trade_id' => $trade->id,
                'from' => $old,
                'to' => $new,
                'rate_source' => CostRateSource::LabourMaster->value,
            ];
            if ($apply) {
                $component->forceFill([
                    'rate' => $new,
                    'rate_source' => CostRateSource::LabourMaster,
                    'updated_by' => $user?->id,
                ])->save();
            }
        }

        return ['changes' => $changes, 'unchanged' => $unchanged];
    }
}
