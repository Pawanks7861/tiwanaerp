<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\MeasurementSheetStatus;
use App\Enums\Boq\SteelUnitWeightMethod;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqBbsAdjustment;
use App\Models\Boq\BoqBbsLine;
use App\Models\Boq\BoqBbsSteelMap;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMaterialNorm;
use App\Models\Boq\BoqMeasurementLine;
use App\Models\Boq\BoqMeasurementSheet;
use App\Models\Boq\BoqPlanningMap;
use App\Models\Boq\BoqRateComparisonQuote;
use App\Models\Planning\ProjectTask;
use App\Models\User;
use App\Support\Math\Decimal;

/**
 * Deep-clones Advanced BOQ children onto a new draft revision using old→new item id map.
 * Inherits rate/wastage snapshots; never refreshes live masters; never posts stock/finance/progress.
 */
class AdvancedBoqRevisionCloneService
{
    public function __construct(
        private readonly BoqService $boqs,
        private readonly SteelUnitWeightProfileService $steelProfiles,
    ) {}

    /**
     * @param  array<int, int>  $itemMap  old item id => new item id
     * @return array<string, int|list<string>>
     */
    public function cloneChildren(Boq $source, Boq $revision, array $itemMap, ?User $user = null): array
    {
        $counts = [
            'items' => count($itemMap),
            'measurements' => 0,
            'measurement_lines' => 0,
            'material_norms' => 0,
            'cost_components' => 0,
            'bbs' => 0,
            'bbs_lines' => 0,
            'rate_comparison_quotes' => 0,
            'planning_maps' => 0,
            'planning_maps_needs_review' => 0,
            'warnings' => [],
        ];

        if ($itemMap === []) {
            return $counts;
        }

        foreach ($itemMap as $oldItemId => $newItemId) {
            $oldItem = BoqItem::query()->whereKey($oldItemId)->where('boq_id', $source->id)->first();
            $newItem = BoqItem::query()->whereKey($newItemId)->where('boq_id', $revision->id)->first();
            if ($oldItem === null || $newItem === null) {
                continue;
            }

            $counts['measurements'] += $this->cloneMeasurements($oldItem, $newItem, $revision, $user, $counts);
            $counts['material_norms'] += $this->cloneMaterialNorms($oldItem, $newItem, $revision, $user);
            $counts['cost_components'] += $this->cloneCostComponents($oldItem, $newItem, $revision, $user);
            $bbsCounts = $this->cloneBbs($oldItem, $newItem, $revision, $user);
            $counts['bbs'] += $bbsCounts['bbs'];
            $counts['bbs_lines'] += $bbsCounts['lines'];
            $counts['rate_comparison_quotes'] += $this->cloneRateComparisons($oldItem, $newItem, $revision, $user);
            $mapResult = $this->clonePlanningMaps($oldItem, $newItem, $revision, $user);
            $counts['planning_maps'] += $mapResult['cloned'];
            $counts['planning_maps_needs_review'] += $mapResult['needs_review'];
            foreach ($mapResult['warnings'] as $warning) {
                $counts['warnings'][] = $warning;
            }
        }

        $this->boqs->recalculateTotals($revision->fresh());

        return $counts;
    }

    /**
     * @param  array<string, int|list<string>>  $counts
     */
    private function cloneMeasurements(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user, array &$counts): int
    {
        $sourceSheet = BoqMeasurementSheet::query()
            ->where('boq_item_id', $oldItem->id)
            ->where('status', MeasurementSheetStatus::Approved)
            ->latest('id')
            ->first()
            ?? BoqMeasurementSheet::query()
                ->where('boq_item_id', $oldItem->id)
                ->latest('id')
                ->first();

        if ($sourceSheet === null) {
            return 0;
        }

        $sourceSheet->load('lines');

        $clone = new BoqMeasurementSheet;
        $clone->forceFill([
            'company_id' => $revision->company_id,
            'project_id' => $revision->project_id,
            'boq_id' => $revision->id,
            'boq_item_id' => $newItem->id,
            'line_uid' => $newItem->line_uid,
            'drawing_revision_id' => $sourceSheet->drawing_revision_id,
            'title' => $sourceSheet->title,
            'description' => $sourceSheet->description,
            'status' => MeasurementSheetStatus::Draft,
            'circle_constant_mode' => $sourceSheet->circle_constant_mode,
            'circle_constant_value' => $sourceSheet->circle_constant_value,
            'calculation_profile_snapshot' => $sourceSheet->calculation_profile_snapshot,
            'total_quantity' => '0',
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ])->save();

        $total = Decimal::zero();
        foreach ($sourceSheet->lines as $line) {
            $input = [
                'formula_type' => $line->formula_type?->value ?? (string) $line->getRawOriginal('formula_type'),
                'nos' => $line->nos !== null ? (string) $line->nos : null,
                'length' => $line->length !== null ? (string) $line->length : null,
                'width' => $line->width !== null ? (string) $line->width : null,
                'height_depth' => $line->height_depth !== null ? (string) $line->height_depth : null,
                'diameter' => $line->diameter !== null ? (string) $line->diameter : null,
                'radius' => $line->radius !== null ? (string) $line->radius : null,
                'manual_quantity' => $line->manual_quantity !== null ? (string) $line->manual_quantity : null,
                'addition_quantity' => (string) ($line->addition_quantity ?? '0'),
                'deduction_quantity' => (string) ($line->deduction_quantity ?? '0'),
                'circle_constant_mode' => $sourceSheet->circle_constant_mode?->value,
                'circle_constant_value' => $sourceSheet->circle_constant_value !== null
                    ? (string) $sourceSheet->circle_constant_value
                    : null,
            ];
            $calc = MeasurementCalculator::line($input);

            $copy = new BoqMeasurementLine;
            $attrs = collect($line->getAttributes())->except([
                'id', 'boq_measurement_sheet_id', 'created_at', 'updated_at',
                'calculated_base', 'calculated_quantity',
            ])->all();
            $copy->forceFill(array_merge($attrs, [
                'boq_measurement_sheet_id' => $clone->id,
                'calculated_base' => $calc['calculated_base'],
                'calculated_quantity' => $calc['calculated_quantity'],
            ]))->save();

            $total = $total->plus($calc['calculated_quantity']);
            $counts['measurement_lines'] = (int) $counts['measurement_lines'] + 1;
        }

        $clone->forceFill([
            'total_quantity' => $total->round(Decimal::QTY_SCALE)->toQuantity(),
        ])->save();

        return 1;
    }

    private function cloneMaterialNorms(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user): int
    {
        $norms = BoqMaterialNorm::query()
            ->where('boq_item_id', $oldItem->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $n = 0;
        foreach ($norms as $norm) {
            $copy = $norm->replicate(['boq_id', 'boq_item_id', 'project_id', 'company_id']);
            $copy->forceFill([
                'company_id' => $revision->company_id,
                'project_id' => $revision->project_id,
                'boq_id' => $revision->id,
                'boq_item_id' => $newItem->id,
                'boq_line_uid' => $newItem->line_uid,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();
            $n++;
        }

        return $n;
    }

    private function cloneCostComponents(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user): int
    {
        $components = BoqCostComponent::query()
            ->where('boq_item_id', $oldItem->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($components->isEmpty()) {
            return 0;
        }

        $sheetInput = [];
        $meta = [];
        foreach ($components->values() as $i => $component) {
            $sheetInput[$i] = [
                'component_type' => $component->component_type?->value ?? (string) $component->getRawOriginal('component_type'),
                'calculation_basis' => $component->calculation_basis?->value
                    ?? (string) ($component->getRawOriginal('calculation_basis') ?? 'quantity_rate'),
                'consumption_qty' => (string) $component->consumption_qty,
                'rate' => (string) $component->rate,
                'base_rate' => $component->base_rate !== null ? (string) $component->base_rate : null,
                'factor' => $component->factor !== null ? (string) $component->factor : null,
                'factor_reference' => $component->factor_reference,
                'percent_value' => $component->percent_value !== null ? (string) $component->percent_value : null,
                'wastage_percent' => (string) ($component->wastage_percent ?? '0'),
                'amount_per_boq_unit' => (string) $component->amount_per_boq_unit,
            ];
            $meta[$i] = $component;
        }

        $calculated = CostBuildUpCalculator::calculateSheet($sheetInput);
        $typeTotals = [
            CostComponentType::Material->value => Decimal::zero(),
            CostComponentType::Labour->value => Decimal::zero(),
            CostComponentType::Equipment->value => Decimal::zero(),
            CostComponentType::Subcontract->value => Decimal::zero(),
            CostComponentType::Other->value => Decimal::zero(),
        ];

        foreach ($calculated as $i => $calc) {
            /** @var BoqCostComponent $source */
            $source = $meta[$i];
            $type = (string) $calc['component_type'];
            $copy = $source->replicate(['boq_id', 'boq_item_id', 'project_id', 'company_id']);
            $copy->forceFill([
                'company_id' => $revision->company_id,
                'project_id' => $revision->project_id,
                'boq_id' => $revision->id,
                'boq_item_id' => $newItem->id,
                'boq_line_uid' => $newItem->line_uid,
                'calculation_basis' => $calc['calculation_basis'],
                'consumption_qty' => $calc['consumption_qty'],
                'rate' => $calc['rate'],
                'base_rate' => $calc['base_rate'],
                'factor' => $calc['factor'],
                'percent_value' => $calc['percent_value'],
                'wastage_percent' => $calc['wastage_percent'],
                'calculated_rate' => $calc['calculated_rate'] ?? null,
                'amount_per_boq_unit' => $calc['amount_per_boq_unit'],
                'is_active' => true,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();

            if (isset($typeTotals[$type])) {
                $typeTotals[$type] = $typeTotals[$type]->plus($calc['amount_per_boq_unit']);
            } else {
                $typeTotals[CostComponentType::Other->value] = $typeTotals[CostComponentType::Other->value]
                    ->plus($calc['amount_per_boq_unit']);
            }
        }

        // Preserve commercial client_rate from item replicate; only re-sync internal cost columns.
        $material = $typeTotals[CostComponentType::Material->value]->toRate();
        $labour = $typeTotals[CostComponentType::Labour->value]->toRate();
        $equipment = $typeTotals[CostComponentType::Equipment->value]->toRate();
        $subcontract = $typeTotals[CostComponentType::Subcontract->value]
            ->plus($typeTotals[CostComponentType::Other->value])
            ->toRate();
        $costRate = Decimal::sum([$material, $labour, $equipment, $subcontract])->toRate();
        $clientRate = (string) $newItem->client_rate;

        $calc = BoqCalculator::line([
            'quantity' => (string) $newItem->quantity,
            'material_rate' => $material,
            'labour_rate' => $labour,
            'equipment_rate' => $equipment,
            'subcontract_rate' => $subcontract,
            'margin_percent' => BoqCalculator::impliedMargin($costRate, $clientRate),
            'client_rate' => $clientRate,
        ]);
        $newItem->forceFill($calc)->save();

        return count($calculated);
    }

    /**
     * @return array{bbs: int, lines: int}
     */
    private function cloneBbs(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user): array
    {
        $source = BoqBbs::query()
            ->where('boq_item_id', $oldItem->id)
            ->with(['lines', 'steelMaps', 'adjustments'])
            ->first();

        if ($source === null) {
            return ['bbs' => 0, 'lines' => 0];
        }

        $bbs = new BoqBbs;
        $bbs->forceFill([
            'company_id' => $revision->company_id,
            'project_id' => $revision->project_id,
            'boq_id' => $revision->id,
            'boq_item_id' => $newItem->id,
            'boq_line_uid' => $newItem->line_uid,
            'drawing_revision_id' => $source->drawing_revision_id,
            'title' => $source->title,
            'description' => $source->description,
            'status' => BbsStatus::Draft,
            'wastage_percent' => (string) $source->wastage_percent,
            'unit_weight_method' => $source->unit_weight_method,
            'steel_profile_id' => $source->steel_profile_id,
            'bar_count_rounding' => $source->bar_count_rounding,
            'unit_weight_profile_snapshot' => $source->unit_weight_profile_snapshot,
            'total_theoretical_weight' => '0',
            'adjusted_theoretical_weight' => '0',
            'wastage_weight' => '0',
            'required_weight' => '0',
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ])->save();

        $newItem->forceFill(['bbs_applicable' => true])->save();

        $profileWeights = null;
        $method = $source->unit_weight_method instanceof SteelUnitWeightMethod
            ? $source->unit_weight_method
            : SteelUnitWeightMethod::tryFrom((string) ($source->getRawOriginal('unit_weight_method') ?? ''))
                ?? SteelUnitWeightMethod::FormulaD2162;

        if ($method === SteelUnitWeightMethod::Profile) {
            if (is_array($source->unit_weight_profile_snapshot['weights'] ?? null)) {
                $profileWeights = [];
                foreach ($source->unit_weight_profile_snapshot['weights'] as $dia => $weight) {
                    $profileWeights[(int) $dia] = (string) $weight;
                }
            } elseif ($source->steel_profile_id) {
                $profile = $source->steelProfile()->first();
                if ($profile) {
                    $profileWeights = $this->steelProfiles->diameterWeightMap($profile);
                }
            }
        }

        $theoByDia = [];
        $lineCount = 0;
        foreach ($source->lines as $line) {
            $row = [
                'shape_code' => $line->shape_code?->value ?? (string) $line->getRawOriginal('shape_code'),
                'diameter_mm' => (int) $line->diameter_mm,
                'number_of_members' => (int) $line->number_of_members,
                'bars_per_member' => (int) $line->bars_per_member,
                'count_mode' => $line->count_mode?->value ?? 'manual',
                'distribution_length' => $line->distribution_length !== null ? (string) $line->distribution_length : null,
                'start_cover' => $line->start_cover !== null ? (string) $line->start_cover : null,
                'end_cover' => $line->end_cover !== null ? (string) $line->end_cover : null,
                'spacing' => $line->spacing !== null ? (string) $line->spacing : null,
                'include_end_bar' => (bool) $line->include_end_bar,
                'faces_layers_multiplier' => (int) ($line->faces_layers_multiplier ?? 1),
                'bar_count_rounding' => $line->bar_count_rounding?->value
                    ?? $bbs->bar_count_rounding?->value
                    ?? 'ceil',
                'a_length' => $line->a_length !== null ? (string) $line->a_length : null,
                'b_length' => $line->b_length !== null ? (string) $line->b_length : null,
                'c_length' => $line->c_length !== null ? (string) $line->c_length : null,
                'd_length' => $line->d_length !== null ? (string) $line->d_length : null,
                'e_length' => $line->e_length !== null ? (string) $line->e_length : null,
                'hook_length' => (string) ($line->hook_length ?? '0'),
                'bend_deduction' => (string) ($line->bend_deduction ?? '0'),
                'lap_mode' => $line->lap_mode?->value ?? 'manual',
                'lap_diameter_factor' => $line->lap_diameter_factor !== null ? (string) $line->lap_diameter_factor : null,
                'lap_length' => $line->lap_length !== null ? (string) $line->lap_length : null,
                'lap_count_per_bar' => $line->lap_count_per_bar !== null ? (string) $line->lap_count_per_bar : null,
                'unit_weight_method' => $method->value,
                'unit_weight_override' => $line->unit_weight_override !== null ? (string) $line->unit_weight_override : null,
                'unit_weight_override_reason' => $line->unit_weight_override_reason,
            ];
            $calc = BbsCalculator::line($row, $profileWeights);

            $copy = new BoqBbsLine;
            $copy->forceFill([
                'bbs_id' => $bbs->id,
                'sequence' => $line->sequence,
                'structure_location' => $line->structure_location,
                'bar_mark' => $line->bar_mark,
                'description' => $line->description,
                'diameter_mm' => (int) $line->diameter_mm,
                'number_of_members' => $calc['number_of_members'],
                'bars_per_member' => $calc['bars_per_member'],
                'total_bars' => $calc['total_bars'],
                'count_mode' => $calc['count_mode'],
                'distribution_length' => $row['distribution_length'],
                'start_cover' => $row['start_cover'],
                'end_cover' => $row['end_cover'],
                'spacing' => $row['spacing'],
                'include_end_bar' => $row['include_end_bar'],
                'faces_layers_multiplier' => $row['faces_layers_multiplier'],
                'bar_count_rounding' => $row['bar_count_rounding'],
                'shape_code' => $row['shape_code'],
                'a_length' => $row['a_length'],
                'b_length' => $row['b_length'],
                'c_length' => $row['c_length'],
                'd_length' => $row['d_length'],
                'e_length' => $row['e_length'],
                'hook_length' => $row['hook_length'],
                'bend_deduction' => $row['bend_deduction'],
                'lap_mode' => $calc['lap_mode'],
                'lap_diameter_factor' => $row['lap_diameter_factor'],
                'lap_length' => $calc['lap_length'],
                'lap_count_per_bar' => $calc['lap_count_per_bar'],
                'cutting_length' => $calc['cutting_length'],
                'total_length' => $calc['total_length'],
                'unit_weight' => $calc['unit_weight'],
                'unit_weight_override' => $row['unit_weight_override'],
                'unit_weight_override_reason' => $row['unit_weight_override_reason'],
                'unit_weight_override_by' => $line->unit_weight_override_by,
                'unit_weight_override_at' => $line->unit_weight_override_at,
                'theoretical_weight' => $calc['theoretical_weight'],
                'remarks' => $line->remarks,
            ])->save();

            $d = (int) $line->diameter_mm;
            $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus($calc['theoretical_weight'])->toQuantity();
            $lineCount++;
        }

        foreach ($source->adjustments as $adj) {
            $adjCopy = new BoqBbsAdjustment;
            $adjCopy->forceFill([
                'bbs_id' => $bbs->id,
                'diameter_mm' => $adj->diameter_mm,
                'material_id' => $adj->material_id,
                'adjustment_qty_kg' => $adj->adjustment_qty_kg,
                'reason' => $adj->reason,
                'reference' => $adj->reference,
                'created_by' => $user?->id,
            ])->save();
        }

        $adjByDia = [];
        foreach (BoqBbsAdjustment::query()->where('bbs_id', $bbs->id)->get() as $adj) {
            $d = (int) $adj->diameter_mm;
            $adjByDia[$d] = Decimal::of($adjByDia[$d] ?? '0')->plus((string) $adj->adjustment_qty_kg)->toQuantity();
        }

        $totals = BbsCalculator::headerTotals(
            array_values($theoByDia),
            (string) $source->wastage_percent,
            array_values($adjByDia),
        );

        $bbs->forceFill([
            'wastage_percent' => $totals['wastage_percent'],
            'total_theoretical_weight' => $totals['total_theoretical_weight'],
            'adjusted_theoretical_weight' => $totals['adjusted_theoretical_weight'],
            'wastage_weight' => $totals['wastage_weight'],
            'required_weight' => $totals['required_weight'],
        ])->save();

        foreach ($source->steelMaps as $map) {
            $mapCopy = new BoqBbsSteelMap;
            $mapCopy->forceFill([
                'bbs_id' => $bbs->id,
                'diameter_mm' => $map->diameter_mm,
                'material_id' => $map->material_id,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();
        }

        return ['bbs' => 1, 'lines' => $lineCount];
    }

    private function cloneRateComparisons(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user): int
    {
        $quotes = BoqRateComparisonQuote::query()
            ->where('boq_item_id', $oldItem->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($quotes->isEmpty()) {
            return 0;
        }

        $rows = [];
        foreach ($quotes as $i => $quote) {
            $calc = RateComparisonCalculator::quote([
                'quoted_rate' => (string) $quote->quoted_rate,
                'gst_mode' => $quote->gst_mode?->value ?? 'inclusive',
                'gst_percent' => $quote->gst_percent !== null ? (string) $quote->gst_percent : null,
                'internal_cost_rate' => (string) $newItem->cost_rate,
                'approved_boq_rate' => (string) $newItem->client_rate,
            ]);
            $rows[] = array_merge($calc, [
                'vendor_id' => $quote->vendor_id,
                'vendor_name' => $quote->vendor_name,
                'remarks' => $quote->remarks,
                'sequence' => $i,
                'is_active' => true,
                // Selection/recommendation is revision-sensitive — never carry auto-award forward.
                'is_lowest' => false,
            ]);
        }

        $rows = RateComparisonCalculator::markLowest($rows);
        foreach ($rows as $row) {
            $copy = new BoqRateComparisonQuote;
            $copy->forceFill([
                'company_id' => $revision->company_id,
                'project_id' => $revision->project_id,
                'boq_id' => $revision->id,
                'boq_item_id' => $newItem->id,
                'boq_line_uid' => $newItem->line_uid,
                'vendor_id' => $row['vendor_id'],
                'vendor_name' => $row['vendor_name'],
                'quoted_rate' => $row['quoted_rate'],
                'gst_mode' => $row['gst_mode'],
                'gst_percent' => $row['gst_percent'],
                'effective_rate' => $row['effective_rate'],
                'internal_cost_rate_snapshot' => $row['internal_cost_rate_snapshot'],
                'approved_boq_rate_snapshot' => $row['approved_boq_rate_snapshot'],
                'variance_vs_internal' => $row['variance_vs_internal'],
                'variance_vs_internal_percent' => $row['variance_vs_internal_percent'],
                'variance_vs_approved' => $row['variance_vs_approved'],
                'variance_vs_approved_percent' => $row['variance_vs_approved_percent'],
                'is_lowest' => $row['is_lowest'],
                'remarks' => $row['remarks'],
                'sequence' => $row['sequence'],
                'is_active' => true,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();
        }

        return count($rows);
    }

    /**
     * @return array{cloned: int, needs_review: int, warnings: list<string>}
     */
    private function clonePlanningMaps(BoqItem $oldItem, BoqItem $newItem, Boq $revision, ?User $user): array
    {
        $maps = BoqPlanningMap::query()
            ->where('boq_item_id', $oldItem->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $cloned = 0;
        $needsReview = 0;
        $warnings = [];

        foreach ($maps as $map) {
            $task = ProjectTask::withTrashed()
                ->whereKey($map->project_task_id)
                ->where('project_id', $revision->project_id)
                ->first();

            $review = false;
            $reason = null;
            if ($task === null) {
                $warnings[] = "Planning map skipped: activity #{$map->project_task_id} missing for item {$newItem->item_code}.";

                continue;
            }
            if ($task->trashed()) {
                $review = true;
                $reason = 'Planning activity is archived; mapping needs review.';
                $needsReview++;
            } elseif ($task->isPlanningHead()) {
                $review = true;
                $reason = 'Mapped node is a planning head; mapping needs review.';
                $needsReview++;
            }

            $copy = $map->replicate(['boq_id', 'boq_item_id', 'project_id', 'company_id']);
            $copy->forceFill([
                'company_id' => $revision->company_id,
                'project_id' => $revision->project_id,
                'boq_id' => $revision->id,
                'boq_item_id' => $newItem->id,
                'boq_line_uid' => $newItem->line_uid,
                'project_task_id' => $task->id,
                'needs_review' => $review,
                'review_reason' => $reason,
                'is_active' => true,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();
            $cloned++;
            if ($review) {
                $warnings[] = "Planning map for {$newItem->item_code} cloned as needs_review: {$reason}";
            }
        }

        return ['cloned' => $cloned, 'needs_review' => $needsReview, 'warnings' => $warnings];
    }
}
