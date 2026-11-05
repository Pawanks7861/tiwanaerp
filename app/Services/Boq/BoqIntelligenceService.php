<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\MeasurementSheetStatus;
use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\VariationStatus;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMeasurementSheet;
use App\Models\Boq\BoqVariation;
use App\Models\Projects\Project;
use App\Services\Planning\ProgressLedgerService;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Stage 9 — Advanced BOQ decision layer (read-only).
 *
 * Aggregates Stages 1–8 authoritative sources. Never posts stock/cost/Tally.
 * Double-count rule: variation-originated items are in BOQ queries once;
 * contract commercial deltas use variation revenue_impact only for non-materialized
 * increments (original lines keep baseline client_amount; VO revenue_impact = delta).
 */
class BoqIntelligenceService
{
    public function __construct(
        private readonly EffectiveBoqQuantityService $effectiveQty,
        private readonly EstimatedActualCostService $costs,
        private readonly MaterialRequirementService $materials,
        private readonly MaterialReconciliationService $reconciliation,
        private readonly ProgressLedgerService $progress,
    ) {}

    /**
     * Project BOQ Dashboard cards + health counts.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Project $project, bool $canCosts): array
    {
        $contract = $this->effectiveQty->contractValueSummary($project);
        $estimate = $this->costs->projectReport($project);
        $totals = $estimate['totals'];
        $executed = $this->progress->executedByLine($project);
        $billed = $this->certifiedBilledByLine($project);
        $billing = $this->projectBillingCash($project);

        $effectiveMap = $this->effectiveQty->mapForProject($project);
        $totalEffective = Decimal::zero();
        $totalExecuted = Decimal::zero();
        foreach ($effectiveMap as $uid => $qty) {
            $totalEffective = $totalEffective->plus($qty);
            $totalExecuted = $totalExecuted->plus($executed[$uid] ?? '0');
        }
        $progressPct = $totalEffective->isPositive()
            ? $totalExecuted->dividedBy($totalEffective)->times(100)->round(2)->toString()
            : null;

        $est = Decimal::of($totals['estimated_cost'] ?? '0');
        $act = Decimal::of($totals['actual_cost'] ?? '0');
        $effContract = Decimal::of($contract['effective_contract_value']);
        $margin = $canCosts ? $effContract->minus($est)->toMoney() : null;

        $cards = [
            'original_contract_value' => $contract['original_contract_value'],
            'approved_variation_value' => $contract['approved_variation_value'],
            'effective_contract_value' => $contract['effective_contract_value'],
            'pending_variation_value' => $contract['pending_variation_value'],
            'progress_percent' => $progressPct,
            'billed_value' => $billing['billed'],
            'received_value' => $billing['received'],
            'outstanding_value' => $billing['outstanding'],
            'remaining_contract_value' => $effContract->minus(Decimal::of($billing['billed']))->toMoney(),
        ];

        if ($canCosts) {
            $cards['estimated_cost'] = $totals['estimated_cost'];
            $cards['original_estimated_cost'] = $totals['original_estimated_cost'] ?? null;
            $cards['variation_estimated_cost'] = $totals['variation_estimated_cost'] ?? null;
            $cards['actual_cost'] = $totals['actual_cost'];
            $cards['estimated_margin'] = $margin;
            $cards['cost_variance'] = $totals['variance'] ?? $est->minus($act)->toMoney();
        }

        return [
            'contract' => $contract,
            'cards' => $cards,
            'health' => $this->healthCounts($project, $estimate, $canCosts),
            'variation_widget' => $this->variationWidget($project, $canCosts),
        ];
    }

    /**
     * @return array{approved_value: ?string, pending_value: ?string, count: int, pending_count: int, approved_count: int}
     */
    public function variationWidget(Project $project, bool $canCosts): array
    {
        $rows = BoqVariation::query()->where('project_id', $project->id)->whereNull('deleted_at')->get(['status', 'revenue_impact']);
        $approved = $rows->where('status', VariationStatus::Approved);
        $pending = $rows->filter(fn ($v) => in_array($v->status, [VariationStatus::Draft, VariationStatus::Submitted], true));

        return [
            'count' => $rows->count(),
            'approved_count' => $approved->count(),
            'pending_count' => $pending->count(),
            'approved_value' => $canCosts ? Decimal::of((string) $approved->sum(fn ($v) => (string) $v->revenue_impact))->toMoney() : null,
            'pending_value' => $canCosts ? Decimal::of((string) $pending->sum(fn ($v) => (string) $v->revenue_impact))->toMoney() : null,
        ];
    }

    /**
     * BOQ Item Analysis rows (effective qty authority).
     *
     * @return list<array<string, mixed>>
     */
    public function itemAnalysis(Project $project, bool $canCosts): array
    {
        $estimate = $this->costs->projectReport($project);
        $executed = $this->progress->executedByLine($project);
        $billed = $this->certifiedBilledByLine($project);
        $varCounts = $this->variationCountsByLineUid($project);
        $bbsFlags = $this->bbsStatusByItemId($project);
        $normFlags = $this->normPresenceByItemId($project);

        $rows = [];
        foreach ($estimate['rows'] as $row) {
            if (! empty($row['orphan'])) {
                continue;
            }
            $uid = (string) ($row['line_uid'] ?? '');
            $originated = (bool) ($row['is_variation_originated'] ?? false);
            $eff = Decimal::of($row['boq_qty'] ?? '0');
            $orig = $originated ? Decimal::zero() : Decimal::of($row['original_qty'] ?? '0');
            $varQty = $originated ? $eff : $eff->minus($orig);
            $done = Decimal::of($executed[$uid] ?? '0');
            $bill = Decimal::of($billed[$uid] ?? '0');
            $sell = Decimal::of($row['selling_rate'] ?? '0');
            $costRate = Decimal::of($row['cost_rate'] ?? '0');
            $estCost = Decimal::of($row['estimated_cost'] ?? '0');
            $actCost = Decimal::of($row['actual_cost'] ?? '0');

            $item = [
                'section' => trim(($row['section_code'] ?? '').' '.($row['section_name'] ?? '')) ?: '—',
                'item_code' => $row['item_code'] ?? null,
                'name' => $row['item_name'] ?? '',
                'unit' => $row['unit'] ?? null,
                'quantity_source' => $row['quantity_source'] ?? null,
                'original_qty' => $orig->toQuantity(),
                'variation_qty' => $varQty->toQuantity(),
                'effective_qty' => $eff->toQuantity(),
                'executed_qty' => $done->toQuantity(),
                'remaining_qty' => $eff->minus($done)->toQuantity(),
                'billed_qty' => $bill->toQuantity(),
                'executed_unbilled_qty' => $done->minus($bill)->toQuantity(),
                'progress_percent' => $eff->isPositive() ? $done->dividedBy($eff)->times(100)->round(2)->toString() : null,
                'selling_rate' => $sell->toRate(),
                'material_requirement' => ($normFlags[(int) ($row['boq_item_id'] ?? 0)] ?? false) ? 'Configured' : 'None',
                'bbs_status' => $bbsFlags[(int) ($row['boq_item_id'] ?? 0)] ?? 'N/A',
                'variation_count' => (int) ($varCounts[$uid] ?? 0),
                'boq_id' => $row['boq_id'] ?? null,
                'boq_item_id' => $row['boq_item_id'] ?? null,
                'line_uid' => $uid,
                'is_variation_originated' => $originated,
            ];

            if ($canCosts) {
                $item['cost_rate'] = $costRate->toRate();
                $item['effective_revenue'] = $eff->times($sell)->round(Decimal::MONEY_SCALE)->toMoney();
                $item['estimated_cost'] = $estCost->toMoney();
                $item['actual_cost'] = $actCost->toMoney();
                $item['cost_variance'] = $estCost->minus($actCost)->toMoney();
            }

            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Progress vs Billing rows.
     *
     * @return list<array<string, mixed>>
     */
    public function progressVsBilling(Project $project, bool $canCosts): array
    {
        $items = BoqItem::query()
            ->with(['unit:id,symbol', 'section:id,code,name', 'boq:id,project_id,status,is_current'])
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->where('status', BoqStatus::Approved->value)
                ->where('is_current', true))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $executed = $this->progress->executedByLine($project);
        $billed = $this->certifiedBilledByLine($project);
        $rows = [];

        foreach ($items as $item) {
            $uid = (string) $item->line_uid;
            $eff = Decimal::of($this->effectiveQty->forItem($item));
            $done = Decimal::of($executed[$uid] ?? '0');
            $bill = Decimal::of($billed[$uid] ?? '0');
            $sell = Decimal::of($this->effectiveQty->effectiveSellingRate($item));

            $row = [
                'item_code' => $item->item_code,
                'name' => $item->name,
                'unit' => $item->unit?->symbol,
                'effective_qty' => $eff->toQuantity(),
                'executed_qty' => $done->toQuantity(),
                'progress_percent' => $eff->isPositive() ? $done->dividedBy($eff)->times(100)->round(2)->toString() : null,
                'billed_qty' => $bill->toQuantity(),
                'executed_unbilled' => $done->minus($bill)->toQuantity(),
                'remaining_execution' => $eff->minus($done)->toQuantity(),
                'selling_rate' => $sell->toRate(),
                'boq_item_id' => $item->id,
                'boq_id' => $item->boq_id,
                'line_uid' => $uid,
            ];
            if ($canCosts) {
                $row['effective_value'] = $eff->times($sell)->round(Decimal::MONEY_SCALE)->toMoney();
                $row['billed_value'] = $bill->times($sell)->round(Decimal::MONEY_SCALE)->toMoney();
                $row['remaining_billable_value'] = $done->minus($bill)->times($sell)->round(Decimal::MONEY_SCALE)->toMoney();
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Measurement summary rows.
     *
     * @return list<array<string, mixed>>
     */
    public function measurementSummary(Project $project, array $filters = []): array
    {
        $q = BoqMeasurementSheet::query()
            ->with([
                'item:id,boq_id,item_code,name,line_uid,boq_section_id,unit_id',
                'item.unit:id,symbol',
                'drawingRevision.drawing:id,drawing_number,title',
                'approver:id,name',
                'lines',
            ])
            ->where('project_id', $project->id);

        if (! empty($filters['boq_id'])) {
            $q->where('boq_id', (int) $filters['boq_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['drawing_revision_id'])) {
            $q->where('drawing_revision_id', (int) $filters['drawing_revision_id']);
        }
        if (! empty($filters['boq_item_id'])) {
            $q->where('boq_item_id', (int) $filters['boq_item_id']);
        }

        $rows = [];
        foreach ($q->orderByDesc('id')->get() as $sheet) {
            $itemLabel = trim(($sheet->item?->item_code ? $sheet->item->item_code.' — ' : '').($sheet->item?->name ?? ''));
            $drawing = $sheet->drawingRevision?->drawing
                ? trim(($sheet->drawingRevision->drawing->drawing_number ?? '').' '.($sheet->drawingRevision->revision_number ?? ''))
                : null;
            $status = $sheet->status?->value ?? (string) $sheet->status;
            foreach ($sheet->lines as $line) {
                $rows[] = [
                    'item' => $itemLabel,
                    'sheet' => $sheet->title ?: ('Sheet #'.$sheet->id),
                    'location' => $line->location,
                    'formula' => is_object($line->formula_type) ? $line->formula_type->value : $line->formula_type,
                    'calculated_qty' => Num::qty($line->calculated_quantity),
                    'drawing' => $drawing,
                    'status' => $status,
                    'approved_by' => $sheet->approver?->name,
                    'approved_at' => $sheet->approved_at?->toDateString(),
                    'boq_item_id' => $sheet->boq_item_id,
                    'boq_id' => $sheet->boq_id,
                ];
            }
            if ($sheet->lines->isEmpty()) {
                $rows[] = [
                    'item' => $itemLabel,
                    'sheet' => $sheet->title ?: ('Sheet #'.$sheet->id),
                    'location' => null,
                    'formula' => null,
                    'calculated_qty' => Num::qty($sheet->total_quantity ?? '0'),
                    'drawing' => $drawing,
                    'status' => $status,
                    'approved_by' => $sheet->approver?->name,
                    'approved_at' => $sheet->approved_at?->toDateString(),
                    'boq_item_id' => $sheet->boq_item_id,
                    'boq_id' => $sheet->boq_id,
                ];
            }
        }

        return $rows;
    }

    /**
     * Cost build-up per BOQ item (requires view_costs at caller).
     *
     * @return list<array<string, mixed>>
     */
    public function costBuildUpReport(Project $project): array
    {
        $items = BoqItem::query()
            ->with(['unit:id,symbol', 'section:id,code,name', 'boq:id,is_current,status'])
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->where('status', BoqStatus::Approved->value)
                ->where('is_current', true))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $componentSums = BoqCostComponent::query()
            ->whereIn('boq_item_id', $items->pluck('id'))
            ->where('is_active', true)
            ->selectRaw('boq_item_id, component_type, SUM(amount_per_boq_unit) as amt')
            ->groupBy('boq_item_id', 'component_type')
            ->get()
            ->groupBy('boq_item_id');

        $rows = [];
        foreach ($items as $item) {
            $eff = Decimal::of($this->effectiveQty->forItem($item));
            $byType = collect($componentSums->get($item->id) ?? [])->keyBy(fn ($r) => is_object($r->component_type) ? $r->component_type->value : (string) $r->component_type);
            $mat = Decimal::of((string) ($byType->get('material')?->amt ?? $item->material_rate ?? '0'));
            $lab = Decimal::of((string) ($byType->get('labour')?->amt ?? $item->labour_rate ?? '0'));
            $eq = Decimal::of((string) ($byType->get('equipment')?->amt ?? $item->equipment_rate ?? '0'));
            $sub = Decimal::of((string) ($byType->get('subcontract')?->amt ?? '0'));
            $other = Decimal::of((string) ($byType->get('other')?->amt ?? '0'));
            if ($byType->isEmpty()) {
                $sub = Decimal::of((string) ($item->subcontract_rate ?? '0'));
            }
            $costRate = Decimal::of((string) $item->cost_rate);
            $sell = Decimal::of($this->effectiveQty->effectiveSellingRate($item));
            $marginUnit = $sell->minus($costRate);

            $rows[] = [
                'item' => trim(($item->item_code ? $item->item_code.' — ' : '').$item->name),
                'unit' => $item->unit?->symbol,
                'material_rate' => $mat->toRate(),
                'labour_rate' => $lab->toRate(),
                'equipment_rate' => $eq->toRate(),
                'subcontract_rate' => $sub->toRate(),
                'other_rate' => $other->toRate(),
                'cost_rate' => $costRate->toRate(),
                'selling_rate' => $sell->toRate(),
                'margin_unit' => $marginUnit->toRate(),
                'estimated_cost' => $eff->times($costRate)->round(Decimal::MONEY_SCALE)->toMoney(),
                'estimated_margin' => $eff->times($marginUnit)->round(Decimal::MONEY_SCALE)->toMoney(),
                'effective_qty' => $eff->toQuantity(),
                'boq_item_id' => $item->id,
                'boq_id' => $item->boq_id,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function procurementGap(Project $project): array
    {
        return collect($this->materials->projectSummary($project))->map(function (array $row) {
            $required = Decimal::of($row['required_qty'] ?? '0');
            $mr = Decimal::of($row['mr_qty'] ?? '0');
            $po = Decimal::of($row['po_qty'] ?? '0');
            $grn = Decimal::of($row['grn_qty'] ?? '0');
            $remaining = Decimal::of($row['remaining_requirement'] ?? '0');
            $status = 'Ok';
            if ($required->isPositive() && $mr->isZero()) {
                $status = 'No MR';
            } elseif ($po->lessThan($required) && $grn->lessThan($required)) {
                $status = 'Procurement Pending';
            } elseif ($grn->greaterThan($required)) {
                $status = 'Over Received';
            }

            return [
                'material' => $row['material_name'] ?? $row['material_code'] ?? '',
                'material_code' => $row['material_code'] ?? null,
                'unit' => $row['unit'] ?? null,
                'required' => Num::qty($required),
                'mr' => Num::qty($mr),
                'po' => Num::qty($po),
                'grn' => Num::qty($grn),
                'central_stock' => Num::qty($row['central_stock'] ?? '0'),
                'remaining' => Num::qty($remaining),
                'status' => $status,
                'material_id' => $row['material_id'] ?? null,
                'source' => $row['source'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * @return array{cost: list<array<string,mixed>>, quantity: list<array<string,mixed>>, material: list<array<string,mixed>>}
     */
    public function riskFlags(Project $project, bool $canCosts): array
    {
        $cost = [];
        $quantity = [];
        $material = [];

        if ($canCosts) {
            foreach ($this->costs->projectReport($project)['rows'] as $row) {
                $est = Decimal::of($row['estimated_cost'] ?? '0');
                $act = Decimal::of($row['actual_cost'] ?? '0');
                $eff = Decimal::of($row['boq_qty'] ?? '0');
                $label = trim(($row['item_code'] ? $row['item_code'].' — ' : '').($row['item_name'] ?? ''));
                if ($act->greaterThan($est) && $est->isPositive()) {
                    $cost[] = ['item' => $label, 'flag' => 'Actual > Estimated', 'estimated' => $est->toMoney(), 'actual' => $act->toMoney()];
                }
                $util = $est->isPositive() ? $act->dividedBy($est)->times(100) : Decimal::zero();
                $progress = $eff->isPositive() ? Decimal::of($this->progress->executedByLine($project)[(string) ($row['line_uid'] ?? '')] ?? '0')->dividedBy($eff)->times(100) : Decimal::zero();
                if ($util->greaterThan(80) && $progress->lessThan(40) && $act->isPositive()) {
                    $cost[] = ['item' => $label, 'flag' => 'High cost utilization / low progress', 'utilization' => $util->round(2)->toString(), 'progress' => $progress->round(2)->toString()];
                }
                $marginUnit = Decimal::of($row['selling_rate'] ?? $row['client_rate'] ?? '0')->minus(Decimal::of($row['cost_rate'] ?? '0'));
                if ($marginUnit->isNegative() || $marginUnit->isZero()) {
                    $cost[] = ['item' => $label, 'flag' => 'Low/negative margin per unit', 'margin_unit' => $marginUnit->toRate()];
                }
            }
            $unalloc = Decimal::of($this->costs->projectReport($project)['totals']['actual_unallocated'] ?? '0');
            if ($unalloc->isPositive()) {
                $cost[] = ['item' => 'Project', 'flag' => 'Unallocated actual cost', 'actual' => $unalloc->toMoney()];
            }
        }

        $executed = $this->progress->executedByLine($project);
        $billed = $this->certifiedBilledByLine($project);
        foreach ($this->itemAnalysis($project, false) as $row) {
            $eff = Decimal::of($row['effective_qty']);
            $done = Decimal::of($row['executed_qty']);
            $bill = Decimal::of($row['billed_qty']);
            $label = trim(($row['item_code'] ? $row['item_code'].' — ' : '').$row['name']);
            if ($done->greaterThan($eff) && $eff->isPositive()) {
                $quantity[] = ['item' => $label, 'flag' => 'Progress > Effective Qty', 'effective' => $eff->toQuantity(), 'executed' => $done->toQuantity()];
            }
            if ($bill->greaterThan($done) && $done->isPositive()) {
                $quantity[] = ['item' => $label, 'flag' => 'Billed > Executed', 'billed' => $bill->toQuantity(), 'executed' => $done->toQuantity()];
            }
            if (($row['quantity_source'] ?? null) === QuantitySource::Measurement->value || ($row['quantity_source'] ?? null) === 'measurement') {
                $hasApproved = BoqMeasurementSheet::query()
                    ->where('boq_item_id', $row['boq_item_id'])
                    ->where('status', MeasurementSheetStatus::Approved->value)
                    ->exists();
                if (! $hasApproved) {
                    $quantity[] = ['item' => $label, 'flag' => 'Measurement source without approved sheet'];
                }
            }
            if (($row['quantity_source'] ?? null) === QuantitySource::Takeoff->value || ($row['quantity_source'] ?? null) === 'takeoff') {
                $quantity[] = ['item' => $label, 'flag' => 'Takeoff source — verify linkage'];
            }
        }

        $pendingVoOnDrawing = BoqVariation::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [VariationStatus::Draft->value, VariationStatus::Submitted->value])
            ->whereNotNull('drawing_revision_id')
            ->count();
        if ($pendingVoOnDrawing > 0) {
            $quantity[] = ['item' => 'Variations', 'flag' => "{$pendingVoOnDrawing} pending variation(s) linked to drawing"];
        }

        foreach ($this->reconciliation->materialSummary($project)['rows'] as $row) {
            $allowed = Decimal::of($row['allowed_qty'] ?? '0');
            $net = Decimal::of($row['net_issued'] ?? '0');
            $label = $row['material_name'] ?? $row['material_code'] ?? 'Material';
            if ($net->greaterThan($allowed) && $allowed->isPositive()) {
                $material[] = ['item' => $label, 'flag' => 'Net Issued > Allowed', 'allowed' => Num::qty($allowed), 'net_issued' => Num::qty($net)];
            }
            if (Decimal::of($row['unallocated_issue_qty'] ?? '0')->isPositive()) {
                $material[] = ['item' => $label, 'flag' => 'Unallocated issue qty', 'qty' => Num::qty($row['unallocated_issue_qty'])];
            }
            $req = Decimal::of($row['required_qty'] ?? $row['allowed_qty'] ?? '0');
            $po = Decimal::of($row['po_qty'] ?? '0');
            $grn = Decimal::of($row['grn_qty'] ?? '0');
            if ($req->isPositive() && $po->lessThan($req)) {
                $material[] = ['item' => $label, 'flag' => 'Required > PO', 'required' => Num::qty($req), 'po' => Num::qty($po)];
            }
            if ($po->isPositive() && $grn->lessThan($po)) {
                $material[] = ['item' => $label, 'flag' => 'PO > GRN', 'po' => Num::qty($po), 'grn' => Num::qty($grn)];
            }
        }

        $unmapped = (int) DB::table('boq_bbs_steel_maps as m')
            ->join('boq_bbs as b', 'b.id', '=', 'm.bbs_id')
            ->where('b.project_id', $project->id)
            ->where('b.status', BbsStatus::Approved->value)
            ->whereNull('m.material_id')
            ->count();
        if ($unmapped > 0) {
            $material[] = ['item' => 'BBS', 'flag' => 'Unmapped BBS steel diameter(s)', 'count' => $unmapped];
        }

        return compact('cost', 'quantity', 'material');
    }

    /**
     * @return array<string, int>
     */
    public function healthCounts(Project $project, ?array $estimate = null, bool $canCosts = true): array
    {
        $estimate ??= $this->costs->projectReport($project);
        $risk = $this->riskFlags($project, $canCosts);
        $pendingVo = BoqVariation::query()->where('project_id', $project->id)
            ->whereIn('status', [VariationStatus::Draft->value, VariationStatus::Submitted->value])
            ->whereNull('deleted_at')->count();
        $pendingMeas = BoqMeasurementSheet::query()->where('project_id', $project->id)
            ->whereIn('status', [MeasurementSheetStatus::Draft->value, MeasurementSheetStatus::Submitted->value])->count();
        $overIssued = count(array_filter($risk['material'], fn ($r) => str_contains($r['flag'], 'Net Issued')));
        $overCost = count(array_filter($risk['cost'], fn ($r) => str_contains($r['flag'], 'Actual >')));
        $unmapped = count(array_filter($risk['material'], fn ($r) => str_contains($r['flag'], 'Unmapped')));
        $unbilled = 0;
        foreach ($this->progressVsBilling($project, false) as $row) {
            if (Decimal::of($row['executed_unbilled'])->isPositive()) {
                $unbilled++;
            }
        }
        $unalloc = Decimal::of($estimate['totals']['actual_unallocated'] ?? '0')->isPositive() ? 1 : 0;

        return [
            'over_cost_items' => $overCost,
            'over_issued_materials' => $overIssued,
            'pending_variations' => $pendingVo,
            'pending_measurements' => $pendingMeas,
            'unmapped_bbs_steel' => $unmapped,
            'executed_unbilled_items' => $unbilled,
            'unallocated_project_costs' => $unalloc,
        ];
    }

    /**
     * @return array<string, string> line_uid => billed qty
     */
    public function certifiedBilledByLine(Project $project): array
    {
        $rows = DB::table('client_invoice_items as ii')
            ->join('client_invoices as i', 'i.id', '=', 'ii.client_invoice_id')
            ->where('i.project_id', $project->id)
            ->whereIn('i.status', array_map(fn ($s) => $s->value, ClientInvoiceStatus::certifiedStates()))
            ->whereNotNull('ii.boq_line_uid')
            ->groupBy('ii.boq_line_uid')
            ->selectRaw('ii.boq_line_uid, SUM(ii.current_qty) as qty')
            ->pluck('qty', 'boq_line_uid');

        $out = [];
        foreach ($rows as $uid => $qty) {
            $out[(string) $uid] = Num::qty($qty);
        }

        return $out;
    }

    /**
     * @return array{billed: string, received: string, outstanding: string}
     */
    private function projectBillingCash(Project $project): array
    {
        $billed = Decimal::of((string) (DB::table('client_invoices')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereIn('status', array_map(fn ($s) => $s->value, ClientInvoiceStatus::certifiedStates()))
            ->sum('gross_amount') ?? '0'));

        $received = Decimal::of((string) (DB::table('payments')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->where('direction', 'receipt')
            ->where('status', 'approved')
            ->sum('amount') ?? '0'));

        return [
            'billed' => $billed->toMoney(),
            'received' => $received->toMoney(),
            'outstanding' => $billed->minus($received)->toMoney(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function variationCountsByLineUid(Project $project): array
    {
        return DB::table('boq_variation_items as vi')
            ->join('boq_variations as v', 'v.id', '=', 'vi.variation_id')
            ->where('v.project_id', $project->id)
            ->whereNull('v.deleted_at')
            ->whereNotNull('vi.original_line_uid')
            ->groupBy('vi.original_line_uid')
            ->selectRaw('vi.original_line_uid, COUNT(*) as c')
            ->pluck('c', 'original_line_uid')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function bbsStatusByItemId(Project $project): array
    {
        $rows = BoqBbs::query()->where('project_id', $project->id)->get(['boq_item_id', 'status']);
        $out = [];
        foreach ($rows->groupBy('boq_item_id') as $itemId => $group) {
            if ($group->contains(fn ($b) => $b->status === BbsStatus::Approved)) {
                $out[(int) $itemId] = 'Approved';
            } elseif ($group->contains(fn ($b) => $b->status === BbsStatus::Submitted)) {
                $out[(int) $itemId] = 'Submitted';
            } else {
                $out[(int) $itemId] = 'Draft';
            }
        }

        return $out;
    }

    /**
     * @return array<int, bool>
     */
    private function normPresenceByItemId(Project $project): array
    {
        return DB::table('boq_material_norms as n')
            ->join('boq_items as i', 'i.id', '=', 'n.boq_item_id')
            ->join('boqs as b', 'b.id', '=', 'i.boq_id')
            ->where('b.project_id', $project->id)
            ->where('n.is_active', true)
            ->distinct()
            ->pluck('n.boq_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
