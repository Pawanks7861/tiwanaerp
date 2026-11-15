<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\CostComponentType;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMeasurementLine;
use App\Models\Boq\BoqPlanningMap;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative Advanced BOQ revision comparison by stable line_uid.
 * Used by web UI, API, Internal PDF appendix, and Change Order snapshots.
 */
class AdvancedBoqRevisionComparisonService
{
    public function __construct(
        private readonly BoqBudgetMaterialSyncService $budgetSync,
        private readonly ProjectMaterialControlService $materialControl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(Boq $previous, Boq $current, bool $withCosts = false): array
    {
        if ((int) $previous->project_id !== (int) $current->project_id
            || (string) $previous->boq_number !== (string) $current->boq_number) {
            throw ValidationException::withMessages(['boq' => 'Revisions must belong to the same BOQ number and project.']);
        }

        $this->eagerLoad($previous);
        $this->eagerLoad($current);

        $prevItems = $previous->items->keyBy('line_uid');
        $currItems = $current->items->keyBy('line_uid');
        $uids = $prevItems->keys()->merge($currItems->keys())->unique()->values();

        $items = [];
        $counts = ['added' => 0, 'removed' => 0, 'modified' => 0, 'unchanged' => 0];

        foreach ($uids as $uid) {
            $before = $prevItems->get($uid);
            $after = $currItems->get($uid);
            $row = $this->compareItem($uid, $before, $after, $withCosts);
            if ($row['status'] === 'unchanged') {
                $counts['unchanged']++;
            } else {
                $counts[$row['status']]++;
                $items[] = $row;
            }
        }

        $prevInternal = $withCosts ? $this->internalTotal($previous) : null;
        $currInternal = $withCosts ? $this->internalTotal($current) : null;
        $prevCommercial = Decimal::of((string) $previous->total_client_amount)->toMoney();
        $currCommercial = Decimal::of((string) $current->total_client_amount)->toMoney();

        $prevMargin = $withCosts ? Decimal::of($prevCommercial)->minus($prevInternal)->toMoney() : null;
        $currMargin = $withCosts ? Decimal::of($currCommercial)->minus($currInternal)->toMoney() : null;

        $impact = $this->overallImpact($items);

        $payload = [
            'header' => [
                'project_id' => $current->project_id,
                'boq_number' => $current->boq_number,
                'previous' => $this->revisionMeta($previous),
                'current' => $this->revisionMeta($current),
                'revision_reason' => $current->revision_reason,
                'created_by' => $current->creator?->name,
                'created_at' => $current->created_at?->toIso8601String(),
                'current_is_draft' => $current->status !== BoqStatus::Approved,
            ],
            'summary' => [
                ...$counts,
                'previous_commercial' => $prevCommercial,
                'current_commercial' => $currCommercial,
                'commercial_variance' => Decimal::of($currCommercial)->minus($prevCommercial)->toMoney(),
                'previous_internal' => $prevInternal,
                'current_internal' => $currInternal,
                'internal_variance' => $withCosts ? Decimal::of($currInternal)->minus($prevInternal)->toMoney() : null,
                'previous_margin' => $prevMargin,
                'current_margin' => $currMargin,
                'margin_variance' => $withCosts ? Decimal::of($currMargin)->minus($prevMargin)->toMoney() : null,
                'previous_margin_pct' => $withCosts ? $this->marginPct($prevCommercial, $prevMargin) : null,
                'current_margin_pct' => $withCosts ? $this->marginPct($currCommercial, $currMargin) : null,
                'impact' => $impact,
            ],
            'items' => $items,
            'bbs_dia_summary' => $withCosts ? $this->bbsDiaSummary($previous, $current) : [],
            'material_summary' => $withCosts ? $this->materialSummary($previous, $current) : [],
            'labour_summary' => $withCosts ? $this->labourSummary($previous, $current) : [],
            'planning_changes' => $this->planningChanges($previous, $current),
            'rate_comparison_changes' => $this->rateComparisonChanges($previous, $current),
            'budget_impact' => $withCosts ? $this->budgetImpact($previous, $current) : null,
            'material_control_impact' => $withCosts ? $this->materialControlImpact($current) : null,
        ];

        if (! $withCosts) {
            $payload = $this->redactCosts($payload);
        }

        return $payload;
    }

    private function eagerLoad(Boq $boq): void
    {
        $boq->loadMissing([
            'creator:id,name',
            'approver:id,name',
            'technicalApprover:id,name',
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with([
                'unit:id,symbol,name',
                'section:id,name,code',
                'costComponents' => fn ($c) => $c->where('is_active', true)->orderBy('sequence')->orderBy('id'),
                'measurementSheets.lines',
                'bbs.lines',
                'rateComparisonQuotes' => fn ($q) => $q->where('is_active', true),
                'planningMaps.task:id,name,wbs_code,planned_qty,unit_id',
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionMeta(Boq $boq): array
    {
        return [
            'id' => $boq->id,
            'version' => (int) $boq->version,
            'status' => $boq->status->value,
            'status_label' => $boq->status->label(),
            'is_draft' => $boq->status !== BoqStatus::Approved,
            'approved_at' => $boq->approved_at?->toIso8601String(),
            'approved_by' => $boq->approver?->name,
            'technical_approved_at' => $boq->technical_approved_at?->toIso8601String(),
            'technical_approved_by' => $boq->technicalApprover?->name,
            'title' => $boq->title,
            'label' => $boq->status === BoqStatus::Approved
                ? 'R'.$boq->version
                : 'R'.$boq->version.' (Draft)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compareItem(string $uid, ?BoqItem $before, ?BoqItem $after, bool $withCosts): array
    {
        if ($before === null && $after !== null) {
            return $this->itemRow('added', $uid, null, $after, $withCosts, ['Added'], 'commercial');
        }
        if ($before !== null && $after === null) {
            return $this->itemRow('removed', $uid, $before, null, $withCosts, ['Removed'], 'commercial');
        }

        /** @var BoqItem $before */
        /** @var BoqItem $after */
        $changeTypes = [];
        $technical = false;
        $commercial = false;

        if (trim((string) $before->name) !== trim((string) $after->name)
            || trim((string) ($before->description ?? '')) !== trim((string) ($after->description ?? ''))) {
            $changeTypes[] = 'Description Changed';
        }
        if ((int) $before->unit_id !== (int) $after->unit_id) {
            $changeTypes[] = 'Unit Changed';
            $technical = true;
        }

        $qtyComparable = (int) $before->unit_id === (int) $after->unit_id;
        if ($qtyComparable && ! $this->qtyEq((string) $before->quantity, (string) $after->quantity)) {
            $changeTypes[] = 'Quantity Changed';
            $technical = true;
        }

        $measurementDiff = $this->measurementDiff($before, $after);
        if ($measurementDiff['changed']) {
            $changeTypes[] = 'Measurement Changed';
            $technical = true;
        }

        $materialDiff = $withCosts ? $this->componentDiff($before, $after, CostComponentType::Material) : ['changed' => false, 'rows' => []];
        if ($materialDiff['changed']) {
            $changeTypes = [...$changeTypes, ...$materialDiff['flags']];
            $technical = true;
        }

        $labourDiff = $withCosts ? $this->componentDiff($before, $after, CostComponentType::Labour) : ['changed' => false, 'rows' => []];
        if ($labourDiff['changed']) {
            $changeTypes = [...$changeTypes, ...$labourDiff['flags']];
            $technical = true;
        }

        $bbsDiff = $withCosts ? $this->bbsItemDiff($before, $after) : ['changed' => false, 'rows' => [], 'wastage' => null];
        if ($bbsDiff['changed']) {
            $changeTypes[] = 'BBS Changed';
            if ($bbsDiff['wastage_changed'] ?? false) {
                $changeTypes[] = 'Wastage Changed';
            }
            $technical = true;
        }

        if ((int) ($before->rate_analysis_id ?? 0) !== (int) ($after->rate_analysis_id ?? 0)) {
            $changeTypes[] = 'Rate Analysis Changed';
            $technical = true;
        }

        $prevInternal = $withCosts ? $this->itemInternal($before) : null;
        $currInternal = $withCosts ? $this->itemInternal($after) : null;
        if ($withCosts && ! $this->moneyEq($prevInternal['total'], $currInternal['total'])) {
            $changeTypes[] = 'Internal Cost Changed';
            $technical = true;
        }

        if (! $this->rateEq((string) $before->client_rate, (string) $after->client_rate)) {
            $changeTypes[] = 'Commercial Rate Changed';
            $commercial = true;
        }
        if (! $this->moneyEq((string) $before->client_amount, (string) $after->client_amount)) {
            $changeTypes[] = 'Commercial Amount Changed';
            $commercial = true;
        }

        if ($withCosts) {
            $prevMargin = Decimal::of((string) $before->client_amount)->minus($prevInternal['total'])->toMoney();
            $currMargin = Decimal::of((string) $after->client_amount)->minus($currInternal['total'])->toMoney();
            if (! $this->moneyEq($prevMargin, $currMargin)) {
                $changeTypes[] = 'Margin Changed';
            }
        }

        $planningDiff = $this->itemPlanningDiff($before, $after);
        if ($planningDiff['changed']) {
            $changeTypes[] = 'Planning Mapping Changed';
            $technical = true;
        }

        // Sort-order-only → ignore (presentation).
        if ($changeTypes === []) {
            return $this->itemRow('unchanged', $uid, $before, $after, $withCosts, [], 'none');
        }

        $impact = match (true) {
            $technical && $commercial => 'mixed',
            $technical => 'technical',
            $commercial => 'commercial',
            default => 'description',
        };

        if (! $technical && ! $commercial && in_array('Description Changed', $changeTypes, true)) {
            $impact = 'description';
        }

        $variationClass = $this->variationClass($before, $after, $technical, $commercial);

        return $this->itemRow('modified', $uid, $before, $after, $withCosts, array_values(array_unique($changeTypes)), $impact, [
            'measurements' => $measurementDiff['rows'],
            'materials' => $materialDiff['rows'],
            'labour' => $labourDiff['rows'],
            'bbs' => $bbsDiff['rows'],
            'wastage' => $bbsDiff['wastage'] ?? null,
            'planning' => $planningDiff['rows'],
            'internal' => $withCosts ? [
                'previous' => $prevInternal,
                'current' => $currInternal,
                'difference' => [
                    'material' => Decimal::of($currInternal['material'])->minus($prevInternal['material'])->toMoney(),
                    'labour' => Decimal::of($currInternal['labour'])->minus($prevInternal['labour'])->toMoney(),
                    'equipment' => Decimal::of($currInternal['equipment'])->minus($prevInternal['equipment'])->toMoney(),
                    'other' => Decimal::of($currInternal['other'])->minus($prevInternal['other'])->toMoney(),
                    'total' => Decimal::of($currInternal['total'])->minus($prevInternal['total'])->toMoney(),
                ],
            ] : null,
            'variation_class' => $variationClass,
            'unit_comparable' => $qtyComparable,
        ]);
    }

    /**
     * @param  list<string>  $changeTypes
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function itemRow(
        string $status,
        string $uid,
        ?BoqItem $before,
        ?BoqItem $after,
        bool $withCosts,
        array $changeTypes,
        string $impact,
        array $details = [],
    ): array {
        $item = $after ?? $before;
        $prevQty = $before ? (string) $before->quantity : '0';
        $currQty = $after ? (string) $after->quantity : '0';
        $unitComparable = ($before === null || $after === null)
            || (int) $before->unit_id === (int) $after->unit_id;

        $qtyDiff = null;
        $qtyPct = null;
        if ($status === 'added') {
            $qtyDiff = 'NEW';
        } elseif ($status === 'removed') {
            $qtyDiff = 'REMOVED';
        } elseif (! $unitComparable) {
            $qtyDiff = 'Not Comparable';
        } else {
            $qtyDiff = Decimal::of($currQty)->minus($prevQty)->toQuantity();
            $qtyPct = Decimal::of($prevQty)->isZero()
                ? null
                : Decimal::of($qtyDiff)->dividedBy($prevQty, Decimal::PERCENT_SCALE)->times(100)->round(Decimal::PERCENT_SCALE)->toString();
        }

        $prevClient = $before ? (string) $before->client_rate : '0';
        $currClient = $after ? (string) $after->client_rate : '0';
        $prevClientAmt = $before ? (string) $before->client_amount : '0.00';
        $currClientAmt = $after ? (string) $after->client_amount : '0.00';

        $prevInternalRate = $withCosts && $before ? (string) ($before->cost_rate ?? '0') : null;
        $currInternalRate = $withCosts && $after ? (string) ($after->cost_rate ?? '0') : null;
        $prevInternalAmt = $withCosts && $before ? (string) ($before->cost_amount ?? '0') : null;
        $currInternalAmt = $withCosts && $after ? (string) ($after->cost_amount ?? '0') : null;

        return [
            'status' => $status,
            'line_uid' => $uid,
            'item_code' => $item?->item_code,
            'description' => $item?->name,
            'unit' => $item?->unit?->symbol,
            'unit_id' => $item?->unit_id,
            'previous_qty' => $before ? $prevQty : ($status === 'added' ? '0' : null),
            'current_qty' => $after ? $currQty : ($status === 'removed' ? '0' : null),
            'qty_difference' => $qtyDiff,
            'qty_pct' => $qtyPct,
            'previous_internal_rate' => $prevInternalRate,
            'current_internal_rate' => $currInternalRate,
            'internal_rate_difference' => $withCosts && $prevInternalRate !== null && $currInternalRate !== null
                ? Decimal::of($currInternalRate)->minus($prevInternalRate)->toRate()
                : null,
            'previous_client_rate' => $prevClient,
            'current_client_rate' => $currClient,
            'client_rate_difference' => Decimal::of($currClient)->minus($prevClient)->toRate(),
            'previous_internal_cost' => $prevInternalAmt,
            'current_internal_cost' => $currInternalAmt,
            'internal_cost_difference' => $withCosts && $prevInternalAmt !== null && $currInternalAmt !== null
                ? Decimal::of($currInternalAmt)->minus($prevInternalAmt)->toMoney()
                : null,
            'previous_commercial_amount' => $prevClientAmt,
            'current_commercial_amount' => $currClientAmt,
            'commercial_difference' => Decimal::of($currClientAmt)->minus($prevClientAmt)->toMoney(),
            'change_types' => $changeTypes,
            'impact' => $impact,
            'variation_class' => $details['variation_class'] ?? null,
            'details' => $details,
        ];
    }

    private function variationClass(BoqItem $before, BoqItem $after, bool $technical, bool $commercial): string
    {
        $qtySame = $this->qtyEq((string) $before->quantity, (string) $after->quantity)
            && (int) $before->unit_id === (int) $after->unit_id;
        $rateSame = $this->rateEq((string) $before->client_rate, (string) $after->client_rate);

        if ($technical && ! $commercial) {
            return 'internal_cost_change';
        }
        if ($qtySame && ! $rateSame) {
            return 'rate_variation';
        }
        if (! $qtySame && $rateSame) {
            return 'quantity_variation';
        }
        if ($commercial && $technical) {
            return 'mixed_variation';
        }

        return $commercial ? 'commercial_variation' : 'technical_change';
    }

    /**
     * @return array{changed:bool,rows:list<array<string,mixed>>}
     */
    private function measurementDiff(BoqItem $before, BoqItem $after): array
    {
        $prev = $this->measurementFingerprints($before);
        $curr = $this->measurementFingerprints($after);
        $keys = $prev->keys()->merge($curr->keys())->unique();
        $rows = [];
        $changed = false;

        foreach ($keys as $key) {
            $p = $prev->get($key);
            $c = $curr->get($key);
            if ($p && $c && $this->qtyEq($p['qty'], $c['qty'])
                && $this->qtyEq($p['nos'], $c['nos'])
                && $this->qtyEq($p['length'], $c['length'])
                && $this->qtyEq($p['width'], $c['width'])
                && $this->qtyEq($p['height'], $c['height'])) {
                continue;
            }
            $changed = true;
            $rows[] = [
                'status' => $p === null ? 'added' : ($c === null ? 'removed' : 'modified'),
                'description' => ($c ?? $p)['description'],
                'nos' => ['previous' => $p['nos'] ?? null, 'current' => $c['nos'] ?? null],
                'length' => ['previous' => $p['length'] ?? null, 'current' => $c['length'] ?? null],
                'width' => ['previous' => $p['width'] ?? null, 'current' => $c['width'] ?? null],
                'height' => ['previous' => $p['height'] ?? null, 'current' => $c['height'] ?? null],
                'formula' => ($c ?? $p)['formula'],
                'previous_qty' => $p['qty'] ?? '0',
                'current_qty' => $c['qty'] ?? '0',
                'difference' => Decimal::of($c['qty'] ?? '0')->minus($p['qty'] ?? '0')->toQuantity(),
            ];
        }

        return ['changed' => $changed, 'rows' => $rows];
    }

    /**
     * @return Collection<string, array<string, string|null>>
     */
    private function measurementFingerprints(BoqItem $item): Collection
    {
        $out = collect();
        foreach ($item->measurementSheets as $sheet) {
            foreach ($sheet->lines as $line) {
                /** @var BoqMeasurementLine $line */
                $key = implode('|', [
                    mb_strtolower(trim((string) $line->description)),
                    (string) ($line->formula_type?->value ?? $line->formula_type),
                    Decimal::of((string) $line->nos)->toQuantity(),
                    Decimal::of((string) $line->length)->toQuantity(),
                    Decimal::of((string) $line->width)->toQuantity(),
                    Decimal::of((string) $line->height_depth)->toQuantity(),
                ]);
                // Prefer matching by description+formula only for qty compare when dimensions identical key collisions.
                $matchKey = mb_strtolower(trim((string) $line->description)).'|'.(string) ($line->formula_type?->value ?? '');
                if ($out->has($matchKey)) {
                    $matchKey .= '|'.$line->id;
                }
                $out[$matchKey] = [
                    'description' => (string) $line->description,
                    'nos' => (string) $line->nos,
                    'length' => (string) $line->length,
                    'width' => (string) $line->width,
                    'height' => (string) $line->height_depth,
                    'formula' => $this->humanFormula($line),
                    'qty' => (string) $line->calculated_quantity,
                ];
            }
        }

        return $out;
    }

    private function humanFormula(BoqMeasurementLine $line): string
    {
        $parts = array_filter([
            Decimal::of((string) $line->nos)->toQuantity(),
            Decimal::of((string) $line->length)->isZero() ? null : Decimal::of((string) $line->length)->toQuantity(),
            Decimal::of((string) $line->width)->isZero() ? null : Decimal::of((string) $line->width)->toQuantity(),
            Decimal::of((string) $line->height_depth)->isZero() ? null : Decimal::of((string) $line->height_depth)->toQuantity(),
        ], fn ($v) => $v !== null);

        return implode(' × ', $parts) ?: (string) $line->calculated_quantity;
    }

    /**
     * @return array{changed:bool,rows:list<array<string,mixed>>,flags:list<string>}
     */
    private function componentDiff(BoqItem $before, BoqItem $after, CostComponentType $type): array
    {
        $prev = $before->costComponents->where('component_type', $type)->keyBy(fn (BoqCostComponent $c) => $this->componentKey($c));
        $curr = $after->costComponents->where('component_type', $type)->keyBy(fn (BoqCostComponent $c) => $this->componentKey($c));
        $keys = $prev->keys()->merge($curr->keys())->unique();
        $rows = [];
        $flags = [];
        $changed = false;
        $boqPrev = Decimal::of((string) $before->quantity);
        $boqCurr = Decimal::of((string) $after->quantity);

        foreach ($keys as $key) {
            $p = $prev->get($key);
            $c = $curr->get($key);
            if ($p && $c
                && $this->qtyEq((string) $p->consumption_qty, (string) $c->consumption_qty)
                && $this->rateEq((string) $p->rate, (string) $c->rate)
                && $this->qtyEq((string) ($p->wastage_percent ?? '0'), (string) ($c->wastage_percent ?? '0'))) {
                continue;
            }
            $changed = true;
            $label = ($c ?? $p)?->description ?? 'Component';
            if ($p === null) {
                $flags[] = $type === CostComponentType::Material ? 'Material Added' : 'Labour Qty Changed';
            } elseif ($c === null) {
                $flags[] = $type === CostComponentType::Material ? 'Material Removed' : 'Labour Qty Changed';
            } else {
                if (! $this->qtyEq((string) $p->consumption_qty, (string) $c->consumption_qty)) {
                    $flags[] = $type === CostComponentType::Material ? 'Material Consumption Changed' : 'Labour Qty Changed';
                }
                if (! $this->rateEq((string) $p->rate, (string) $c->rate)) {
                    $flags[] = $type === CostComponentType::Material ? 'Material Rate Changed' : 'Labour Rate Changed';
                }
                if (! $this->qtyEq((string) ($p->wastage_percent ?? '0'), (string) ($c->wastage_percent ?? '0'))) {
                    $flags[] = 'Wastage Changed';
                }
                $flags[] = $type === CostComponentType::Material ? 'Material Cost Changed' : 'Labour Cost Changed';
            }

            $prevNet = $p ? $boqPrev->times((string) $p->consumption_qty)->times(
                Decimal::of('1')->plus(Decimal::of((string) ($p->wastage_percent ?? '0'))->dividedBy(100))
            )->toQuantity() : '0';
            $currNet = $c ? $boqCurr->times((string) $c->consumption_qty)->times(
                Decimal::of('1')->plus(Decimal::of((string) ($c->wastage_percent ?? '0'))->dividedBy(100))
            )->toQuantity() : '0';
            $prevAmt = $p ? Decimal::of((string) $p->amount_per_boq_unit)->times($boqPrev)->toMoney() : '0.00';
            $currAmt = $c ? Decimal::of((string) $c->amount_per_boq_unit)->times($boqCurr)->toMoney() : '0.00';

            $rows[] = [
                'status' => $p === null ? 'added' : ($c === null ? 'removed' : 'modified'),
                'material' => $label,
                'unit' => ($c ?? $p)?->consumption_unit,
                'previous_consumption' => $p ? (string) $p->consumption_qty : null,
                'current_consumption' => $c ? (string) $c->consumption_qty : null,
                'previous_net_qty' => $prevNet,
                'current_net_qty' => $currNet,
                'qty_variance' => Decimal::of($currNet)->minus($prevNet)->toQuantity(),
                'previous_rate' => $p ? (string) $p->rate : null,
                'current_rate' => $c ? (string) $c->rate : null,
                'rate_variance' => Decimal::of($c->rate ?? '0')->minus($p->rate ?? '0')->toRate(),
                'previous_amount' => $prevAmt,
                'current_amount' => $currAmt,
                'cost_variance' => Decimal::of($currAmt)->minus($prevAmt)->toMoney(),
                'previous_wastage_pct' => $p ? (string) ($p->wastage_percent ?? '0') : null,
                'current_wastage_pct' => $c ? (string) ($c->wastage_percent ?? '0') : null,
            ];
        }

        return ['changed' => $changed, 'rows' => $rows, 'flags' => array_values(array_unique($flags))];
    }

    private function componentKey(BoqCostComponent $c): string
    {
        return mb_strtolower(trim((string) $c->description)).'|'.(string) ($c->source_id ?? '').'|'.(string) $c->consumption_unit;
    }

    /**
     * @return array{changed:bool,rows:list<array<string,mixed>>,wastage:?array<string,mixed>,wastage_changed?:bool}
     */
    private function bbsItemDiff(BoqItem $before, BoqItem $after): array
    {
        $prevBbs = $before->bbs;
        $currBbs = $after->bbs;
        if ($prevBbs === null && $currBbs === null) {
            return ['changed' => false, 'rows' => [], 'wastage' => null];
        }

        $prevLines = collect($prevBbs?->lines ?? [])->keyBy(fn ($l) => (int) $l->diameter_mm.'|'.mb_strtolower(trim((string) ($l->bar_mark ?? $l->description ?? $l->id))));
        $currLines = collect($currBbs?->lines ?? [])->keyBy(fn ($l) => (int) $l->diameter_mm.'|'.mb_strtolower(trim((string) ($l->bar_mark ?? $l->description ?? $l->id))));
        // Fallback match by diameter + sequence when marks missing
        if ($prevLines->isEmpty() && $prevBbs) {
            $prevLines = $prevBbs->lines->keyBy(fn ($l, $i) => (int) $l->diameter_mm.'|'.$i);
        }
        if ($currLines->isEmpty() && $currBbs) {
            $currLines = $currBbs->lines->keyBy(fn ($l, $i) => (int) $l->diameter_mm.'|'.$i);
        }

        // Dia-level aggregate for stable compare
        $sum = function (?BoqBbs $bbs): array {
            $out = [];
            if ($bbs === null) {
                return $out;
            }
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $out[$d] = [
                    'length' => Decimal::of($out[$d]['length'] ?? '0')->plus((string) $line->total_length)->toQuantity(),
                    'kg' => Decimal::of($out[$d]['kg'] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity(),
                ];
            }

            return $out;
        };

        $prevByDia = $sum($prevBbs);
        $currByDia = $sum($currBbs);
        $dias = collect(array_keys($prevByDia))->merge(array_keys($currByDia))->unique()->sort()->values();
        $rows = [];
        $changed = false;
        foreach ($dias as $dia) {
            $p = $prevByDia[$dia] ?? ['length' => '0', 'kg' => '0'];
            $c = $currByDia[$dia] ?? ['length' => '0', 'kg' => '0'];
            if ($this->qtyEq($p['length'], $c['length']) && $this->qtyEq($p['kg'], $c['kg'])) {
                continue;
            }
            $changed = true;
            $rows[] = [
                'element' => $after->name,
                'bar_description' => $dia.' mm',
                'dia' => $dia,
                'previous_length' => $p['length'],
                'current_length' => $c['length'],
                'previous_kg' => $p['kg'],
                'current_kg' => $c['kg'],
                'previous_mt' => Decimal::of($p['kg'])->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'current_mt' => Decimal::of($c['kg'])->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'difference_mt' => Decimal::of($c['kg'])->minus($p['kg'])->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
            ];
        }

        $prevW = (string) ($prevBbs?->wastage_percent ?? '0');
        $currW = (string) ($currBbs?->wastage_percent ?? '0');
        $wastageChanged = ! $this->qtyEq($prevW, $currW);
        if ($wastageChanged) {
            $changed = true;
        }

        return [
            'changed' => $changed,
            'rows' => $rows,
            'wastage_changed' => $wastageChanged,
            'wastage' => [
                'previous_pct' => $prevW,
                'current_pct' => $currW,
                'previous_net_mt' => Decimal::of((string) ($prevBbs?->required_weight ?? '0'))->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'current_net_mt' => Decimal::of((string) ($currBbs?->required_weight ?? '0'))->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
            ],
        ];
    }

    /**
     * @return array{changed:bool,rows:list<array<string,mixed>>}
     */
    private function itemPlanningDiff(BoqItem $before, BoqItem $after): array
    {
        $prev = $before->planningMaps->keyBy(fn (BoqPlanningMap $m) => (int) $m->project_task_id);
        $curr = $after->planningMaps->keyBy(fn (BoqPlanningMap $m) => (int) $m->project_task_id);
        $keys = $prev->keys()->merge($curr->keys())->unique();
        $rows = [];
        $changed = false;
        foreach ($keys as $key) {
            $p = $prev->get($key);
            $c = $curr->get($key);
            if ($p && $c
                && $this->qtyEq((string) $p->allocated_boq_qty, (string) $c->allocated_boq_qty)
                && $this->qtyEq((string) ($p->conversion_factor ?? '0'), (string) ($c->conversion_factor ?? '0'))) {
                continue;
            }
            $changed = true;
            $task = ($c ?? $p)?->task;
            $rows[] = [
                'status' => $p === null ? 'added' : ($c === null ? 'removed' : 'modified'),
                'planning_activity' => $task?->name,
                'previous_allocation' => $p ? (string) $p->allocated_boq_qty : null,
                'current_allocation' => $c ? (string) $c->allocated_boq_qty : null,
                'previous_conversion' => $p ? (string) ($p->conversion_factor ?? '') : null,
                'current_conversion' => $c ? (string) ($c->conversion_factor ?? '') : null,
                'mapping_method' => ($c ?? $p)?->mapping_method?->value ?? ($c ?? $p)?->mapping_method,
            ];
        }

        return ['changed' => $changed, 'rows' => $rows];
    }

    /**
     * @return array{material:string,labour:string,equipment:string,other:string,total:string}
     */
    private function itemInternal(BoqItem $item): array
    {
        $qty = Decimal::of((string) $item->quantity);
        $totals = [
            'material' => Decimal::zero(),
            'labour' => Decimal::zero(),
            'equipment' => Decimal::zero(),
            'other' => Decimal::zero(),
        ];
        foreach ($item->costComponents as $c) {
            $amt = Decimal::of((string) $c->amount_per_boq_unit)->times($qty);
            $bucket = match ($c->component_type) {
                CostComponentType::Material => 'material',
                CostComponentType::Labour => 'labour',
                CostComponentType::Equipment => 'equipment',
                default => 'other',
            };
            $totals[$bucket] = $totals[$bucket]->plus($amt);
        }

        // Prefer component totals when present; else fall back to item cost_amount.
        $componentTotal = $totals['material']->plus($totals['labour'])->plus($totals['equipment'])->plus($totals['other']);
        $total = $componentTotal->isZero()
            ? Decimal::of((string) ($item->cost_amount ?? '0'))
            : $componentTotal;

        return [
            'material' => $totals['material']->toMoney(),
            'labour' => $totals['labour']->toMoney(),
            'equipment' => $totals['equipment']->toMoney(),
            'other' => $totals['other']->toMoney(),
            'total' => $total->toMoney(),
        ];
    }

    private function internalTotal(Boq $boq): string
    {
        if (BoqCompanySettings::enabled()) {
            return Decimal::of($this->budgetSync->proposedSyncPayload($boq)['reconciliation']['grand'])->toMoney();
        }

        return Decimal::of((string) $boq->total_cost_amount)->toMoney();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bbsDiaSummary(Boq $previous, Boq $current): array
    {
        $sum = function (Boq $boq): array {
            $out = [];
            foreach ($boq->items as $item) {
                if ($item->bbs === null) {
                    continue;
                }
                foreach ($item->bbs->lines as $line) {
                    $d = (int) $line->diameter_mm;
                    $out[$d] = Decimal::of($out[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                }
            }

            return $out;
        };

        $prev = $sum($previous);
        $curr = $sum($current);
        $rows = [];
        foreach (collect(array_keys($prev))->merge(array_keys($curr))->unique()->sort() as $dia) {
            $from = $prev[$dia] ?? '0.0000';
            $to = $curr[$dia] ?? '0.0000';
            if ($this->qtyEq($from, $to)) {
                continue;
            }
            $diff = Decimal::of($to)->minus($from);
            $rows[] = [
                'diameter_mm' => (int) $dia,
                'previous_mt' => Decimal::of($from)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'current_mt' => Decimal::of($to)->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'difference_mt' => $diff->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                'difference_pct' => Decimal::of($from)->isZero()
                    ? null
                    : $diff->dividedBy($from, Decimal::PERCENT_SCALE)->times(100)->round(Decimal::PERCENT_SCALE)->toString(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function materialSummary(Boq $previous, Boq $current): array
    {
        return $this->aggregateComponents($previous, $current, CostComponentType::Material);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function labourSummary(Boq $previous, Boq $current): array
    {
        return $this->aggregateComponents($previous, $current, CostComponentType::Labour);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateComponents(Boq $previous, Boq $current, CostComponentType $type): array
    {
        $bucket = function (Boq $boq) use ($type): array {
            $out = [];
            foreach ($boq->items as $item) {
                $qty = Decimal::of((string) $item->quantity);
                foreach ($item->costComponents->where('component_type', $type) as $c) {
                    $key = mb_strtolower(trim((string) $c->description)).'|'.(string) $c->consumption_unit;
                    $net = $qty->times((string) $c->consumption_qty)->times(
                        Decimal::of('1')->plus(Decimal::of((string) ($c->wastage_percent ?? '0'))->dividedBy(100))
                    );
                    $amt = Decimal::of((string) $c->amount_per_boq_unit)->times($qty);
                    $out[$key] = [
                        'name' => $c->description,
                        'unit' => $c->consumption_unit,
                        'qty' => Decimal::of($out[$key]['qty'] ?? '0')->plus($net)->toQuantity(),
                        'amount' => Decimal::of($out[$key]['amount'] ?? '0')->plus($amt)->toMoney(),
                    ];
                }
            }

            return $out;
        };

        $prev = $bucket($previous);
        $curr = $bucket($current);
        $rows = [];
        foreach (collect(array_keys($prev))->merge(array_keys($curr))->unique() as $key) {
            $p = $prev[$key] ?? null;
            $c = $curr[$key] ?? null;
            if ($p && $c && $this->qtyEq($p['qty'], $c['qty']) && $this->moneyEq($p['amount'], $c['amount'])) {
                continue;
            }
            $rows[] = [
                'name' => ($c ?? $p)['name'],
                'unit' => ($c ?? $p)['unit'],
                'previous_qty' => $p['qty'] ?? '0',
                'current_qty' => $c['qty'] ?? '0',
                'qty_difference' => Decimal::of($c['qty'] ?? '0')->minus($p['qty'] ?? '0')->toQuantity(),
                'previous_amount' => $p['amount'] ?? '0.00',
                'current_amount' => $c['amount'] ?? '0.00',
                'amount_difference' => Decimal::of($c['amount'] ?? '0')->minus($p['amount'] ?? '0')->toMoney(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planningChanges(Boq $previous, Boq $current): array
    {
        $rows = [];
        $prevItems = $previous->items->keyBy('line_uid');
        $currItems = $current->items->keyBy('line_uid');
        foreach ($prevItems->keys()->merge($currItems->keys())->unique() as $uid) {
            $before = $prevItems->get($uid);
            $after = $currItems->get($uid);
            if ($before === null || $after === null) {
                continue;
            }
            $diff = $this->itemPlanningDiff($before, $after);
            foreach ($diff['rows'] as $row) {
                $rows[] = [...$row, 'line_uid' => $uid, 'item' => $after->name];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rateComparisonChanges(Boq $previous, Boq $current): array
    {
        $bucket = function (Boq $boq): array {
            $out = [];
            foreach ($boq->items as $item) {
                foreach ($item->rateComparisonQuotes as $q) {
                    $key = $item->line_uid.'|'.mb_strtolower(trim((string) $q->vendor_name));
                    $out[$key] = [
                        'line_uid' => $item->line_uid,
                        'item' => $item->name,
                        'vendor' => $q->vendor_name,
                        'rate' => (string) $q->quoted_rate,
                    ];
                }
            }

            return $out;
        };

        $prev = $bucket($previous);
        $curr = $bucket($current);
        $rows = [];
        foreach (collect(array_keys($prev))->merge(array_keys($curr))->unique() as $key) {
            $p = $prev[$key] ?? null;
            $c = $curr[$key] ?? null;
            if ($p && $c && $this->rateEq($p['rate'], $c['rate'])) {
                continue;
            }
            $rows[] = [
                'status' => $p === null ? 'added' : ($c === null ? 'removed' : 'changed'),
                'vendor' => ($c ?? $p)['vendor'],
                'item' => ($c ?? $p)['item'],
                'previous_quote' => $p['rate'] ?? null,
                'current_quote' => $c['rate'] ?? null,
                'difference' => Decimal::of($c['rate'] ?? '0')->minus($p['rate'] ?? '0')->toRate(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetImpact(Boq $previous, Boq $current): array
    {
        $prevPayload = $this->budgetSync->proposedSyncPayload($previous);
        $currPayload = $this->budgetSync->proposedSyncPayload($current);
        $prev = $prevPayload['reconciliation'];
        $curr = $currPayload['reconciliation'];
        $prevSteel = $this->bbsSteelAmountFromProposedLines($prevPayload['lines']);
        $currSteel = $this->bbsSteelAmountFromProposedLines($currPayload['lines']);
        $status = $this->budgetSync->syncStatus($current->project, $current);

        return [
            'material' => [
                'previous' => $prev['material'],
                'current' => $curr['material'],
                'difference' => Decimal::of($curr['material'])->minus($prev['material'])->toMoney(),
            ],
            'labour' => [
                'previous' => $prev['labour'],
                'current' => $curr['labour'],
                'difference' => Decimal::of($curr['labour'])->minus($prev['labour'])->toMoney(),
            ],
            'steel_bbs' => [
                'previous' => $prevSteel,
                'current' => $currSteel,
                'difference' => Decimal::of($currSteel)->minus($prevSteel)->toMoney(),
                'note' => 'Included in material total when BBS is authoritative; shown separately for variance.',
            ],
            'equipment' => [
                'previous' => $prev['equipment'],
                'current' => $curr['equipment'],
                'difference' => Decimal::of($curr['equipment'])->minus($prev['equipment'])->toMoney(),
            ],
            'other' => [
                'previous' => $prev['other'],
                'current' => $curr['other'],
                'difference' => Decimal::of($curr['other'])->minus($prev['other'])->toMoney(),
            ],
            'total' => [
                'previous' => $prev['grand'],
                'current' => $curr['grand'],
                'difference' => Decimal::of($curr['grand'])->minus($prev['grand'])->toMoney(),
            ],
            'budget_status' => $status,
            'previous_boq_revision' => [
                'id' => $previous->id,
                'version' => (int) $previous->version,
            ],
            'current_boq_revision' => [
                'id' => $current->id,
                'version' => (int) $current->version,
            ],
            'sync_required' => ($status['status'] ?? null) !== 'in_sync',
            'note' => 'Comparison is read-only. Budget is not synced automatically.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function bbsSteelAmountFromProposedLines(array $lines): string
    {
        $total = Decimal::zero();
        foreach ($lines as $line) {
            $key = (string) ($line['source_key'] ?? '');
            if (str_starts_with($key, 'adv-bbs:')) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return $total->toMoney();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function materialControlImpact(Boq $current): ?array
    {
        try {
            $report = $this->materialControl->report($current->project);

            return [
                'rows' => collect($report['rows'] ?? [])->take(50)->values()->all(),
                'note' => 'Read-only projected view from Material Control. No ledger mutation.',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function overallImpact(array $items): string
    {
        $tech = false;
        $comm = false;
        foreach ($items as $item) {
            if (in_array($item['impact'], ['technical', 'mixed'], true)) {
                $tech = true;
            }
            if (in_array($item['impact'], ['commercial', 'mixed'], true)) {
                $comm = true;
            }
        }

        return match (true) {
            $tech && $comm => 'mixed',
            $tech => 'technical',
            $comm => 'commercial',
            default => 'none',
        };
    }

    private function marginPct(string $commercial, string $margin): ?string
    {
        if (Decimal::of($commercial)->isZero()) {
            return null;
        }

        return Decimal::of($margin)->dividedBy($commercial, Decimal::PERCENT_SCALE)->times(100)->round(Decimal::PERCENT_SCALE)->toString();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactCosts(array $payload): array
    {
        $payload['summary']['previous_internal'] = null;
        $payload['summary']['current_internal'] = null;
        $payload['summary']['internal_variance'] = null;
        $payload['summary']['previous_margin'] = null;
        $payload['summary']['current_margin'] = null;
        $payload['summary']['margin_variance'] = null;
        $payload['summary']['previous_margin_pct'] = null;
        $payload['summary']['current_margin_pct'] = null;
        $payload['bbs_dia_summary'] = [];
        $payload['material_summary'] = [];
        $payload['labour_summary'] = [];
        $payload['budget_impact'] = null;
        $payload['material_control_impact'] = null;

        foreach ($payload['items'] as &$item) {
            $item['previous_internal_rate'] = null;
            $item['current_internal_rate'] = null;
            $item['internal_rate_difference'] = null;
            $item['previous_internal_cost'] = null;
            $item['current_internal_cost'] = null;
            $item['internal_cost_difference'] = null;
            $item['details']['materials'] = [];
            $item['details']['labour'] = [];
            $item['details']['bbs'] = [];
            $item['details']['internal'] = null;
            $item['change_types'] = array_values(array_filter(
                $item['change_types'],
                fn ($t) => ! str_contains($t, 'Material')
                    && ! str_contains($t, 'Labour')
                    && ! str_contains($t, 'BBS')
                    && ! str_contains($t, 'Internal')
                    && ! str_contains($t, 'Wastage')
                    && ! str_contains($t, 'Margin')
                    && ! str_contains($t, 'Rate Analysis')
            ));
        }

        return $payload;
    }

    private function qtyEq(string $a, string $b): bool
    {
        return Decimal::of($a)->round(Decimal::QTY_SCALE)->equals(Decimal::of($b)->round(Decimal::QTY_SCALE));
    }

    private function rateEq(string $a, string $b): bool
    {
        return Decimal::of($a)->round(Decimal::RATE_SCALE)->equals(Decimal::of($b)->round(Decimal::RATE_SCALE));
    }

    private function moneyEq(string $a, string $b): bool
    {
        return Decimal::of($a)->round(Decimal::MONEY_SCALE)->equals(Decimal::of($b)->round(Decimal::MONEY_SCALE));
    }
}
