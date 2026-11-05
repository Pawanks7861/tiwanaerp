<?php

namespace App\Services\Boq;

use App\Models\Boq\BoqItem;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Stage 6 — Estimated (BOQ Cost Build-Up) vs Actual (project_cost_ledger).
 *
 * Variance = Estimated − Actual  (positive = under budget / favorable).
 * Variance % / Utilization % are null when Estimated = 0 (no division by zero).
 *
 * Estimate scope: BOQ items on non-deleted, non-revised BOQs (same revision filter as Stage 4).
 * Actual: signed SUM of project_cost_ledger (reversals included as negative rows).
 * Payments / GRN / Tally are never read here.
 */
class EstimatedActualCostService
{
    public function __construct(private readonly EffectiveBoqQuantityService $effectiveQty) {}

    /**
     * @param  array{boq_section_id?: int|null, line_uid?: string|null, cost_head?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function projectReport(Project $project, array $filters = []): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $periodLimited = filled($from) || filled($to);

        $estimates = $this->estimateRows($project, $filters);
        $actualByUid = $this->actualByLineUid((int) $project->company_id, (int) $project->id, $from, $to, $filters['cost_head'] ?? null);
        $unallocatedByHead = $this->unallocatedByHead((int) $project->company_id, (int) $project->id, $from, $to);
        $ledgerTotal = $this->ledgerTotal((int) $project->company_id, (int) $project->id, $from, $to);

        $rows = [];
        $estTotal = Decimal::zero();
        $origEstTotal = Decimal::zero();
        $varEstTotal = Decimal::zero();
        $actLinked = Decimal::zero();
        $headEst = array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());
        $headAct = array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());

        foreach ($estimates as $est) {
            $uid = $est['line_uid'];
            $actualHeads = $actualByUid[$uid]['heads'] ?? array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());
            $actualTotal = $actualByUid[$uid]['total'] ?? Decimal::zero();

            if (($filters['cost_head'] ?? null) && ! isset($est['heads'][$filters['cost_head']])) {
                continue;
            }

            $estimated = $est['estimated_cost'];
            $variance = $estimated->minus($actualTotal);
            $row = [
                'boq_id' => $est['boq_id'],
                'boq_number' => $est['boq_number'],
                'boq_section_id' => $est['boq_section_id'],
                'section_code' => $est['section_code'],
                'section_name' => $est['section_name'],
                'boq_item_id' => $est['boq_item_id'],
                'line_uid' => $uid,
                'item_code' => $est['item_code'],
                'item_name' => $est['item_name'],
                'unit' => $est['unit'],
                'quantity_source' => $est['quantity_source'] ?? null,
                'is_variation_originated' => (bool) ($est['is_variation_originated'] ?? false),
                'boq_qty' => $est['boq_qty']->toQuantity(),
                'original_qty' => ($est['original_qty'] ?? Decimal::zero())->toQuantity(),
                'selling_rate' => ($est['selling_rate'] ?? Decimal::zero())->toRate(),
                'cost_rate' => $est['cost_rate']->toRate(),
                'estimated_cost' => $estimated->toMoney(),
                'original_estimated_cost' => ($est['original_estimated_cost'] ?? Decimal::zero())->toMoney(),
                'variation_estimated_cost' => ($est['variation_estimated_cost'] ?? Decimal::zero())->toMoney(),
                'actual_cost' => $actualTotal->toMoney(),
                'variance' => $variance->toMoney(),
                'variance_percent' => Num::percent($variance, $estimated),
                'utilization_percent' => Num::percent($actualTotal, $estimated),
                'status' => $this->statusFlag($estimated, $actualTotal),
                'heads' => [],
            ];

            foreach (BoqCostHeadMapper::DISPLAY_HEADS as $h) {
                $e = $est['heads'][$h] ?? Decimal::zero();
                $a = $actualHeads[$h] ?? Decimal::zero();
                $v = $e->minus($a);
                $row['heads'][$h] = [
                    'estimated' => $e->toMoney(),
                    'actual' => $a->toMoney(),
                    'variance' => $v->toMoney(),
                ];
                $headEst[$h] = $headEst[$h]->plus($e);
                $headAct[$h] = $headAct[$h]->plus($a);
            }

            $estTotal = $estTotal->plus($estimated);
            $origEstTotal = $origEstTotal->plus($est['original_estimated_cost'] ?? Decimal::zero());
            $varEstTotal = $varEstTotal->plus($est['variation_estimated_cost'] ?? Decimal::zero());
            $actLinked = $actLinked->plus($actualTotal);
            $rows[] = $row;

            unset($actualByUid[$uid]);
        }

        // Actual on line_uids that no longer appear on any non-revised BOQ item → treat as unallocated-ish orphan?
        // Spec: do not lose actual via line_uid. Show remaining linked actuals without estimate as orphan rows.
        foreach ($actualByUid as $uid => $bucket) {
            if (($filters['cost_head'] ?? null)) {
                // already filtered in query
            }
            $actualTotal = $bucket['total'];
            if ($actualTotal->isZero()) {
                continue;
            }
            $variance = Decimal::zero()->minus($actualTotal);
            $orphan = [
                'boq_id' => null,
                'boq_number' => null,
                'boq_section_id' => null,
                'section_code' => null,
                'section_name' => null,
                'boq_item_id' => $bucket['boq_item_id'] ?? null,
                'line_uid' => $uid,
                'item_code' => $bucket['item_code'] ?? null,
                'item_name' => $bucket['item_name'] ?? 'Orphan BOQ link',
                'unit' => null,
                'boq_qty' => '0.0000',
                'cost_rate' => '0.0000',
                'estimated_cost' => '0.00',
                'actual_cost' => $actualTotal->toMoney(),
                'variance' => $variance->toMoney(),
                'variance_percent' => null,
                'utilization_percent' => null,
                'status' => 'over',
                'heads' => [],
                'orphan' => true,
            ];
            foreach (BoqCostHeadMapper::DISPLAY_HEADS as $h) {
                $a = $bucket['heads'][$h] ?? Decimal::zero();
                $orphan['heads'][$h] = [
                    'estimated' => '0.00',
                    'actual' => $a->toMoney(),
                    'variance' => $a->negate()->toMoney(),
                ];
                $headAct[$h] = $headAct[$h]->plus($a);
            }
            $actLinked = $actLinked->plus($actualTotal);
            $rows[] = $orphan;
        }

        $unallocatedTotal = Decimal::sum(array_values($unallocatedByHead));
        $actualTotal = $actLinked->plus($unallocatedTotal);
        $variance = $estTotal->minus($actualTotal);

        $headSummary = [];
        foreach (BoqCostHeadMapper::DISPLAY_HEADS as $h) {
            $e = $headEst[$h];
            $a = $headAct[$h];
            $headSummary[] = [
                'cost_head' => $h,
                'label' => BoqCostHeadMapper::label($h),
                'estimated' => $e->toMoney(),
                'actual' => $a->toMoney(),
                'variance' => $e->minus($a)->toMoney(),
            ];
        }
        $headSummary[] = [
            'cost_head' => 'unallocated',
            'label' => 'Unallocated Actual',
            'estimated' => '0.00',
            'actual' => $unallocatedTotal->toMoney(),
            'variance' => $unallocatedTotal->negate()->toMoney(),
        ];

        return [
            'formula_note' => 'Variance = Estimated − Actual (positive = under budget / favorable). '
                .'Estimated = BOQ Qty × Cost Rate from Stage 3 Cost Build-Up on non-revised BOQs. '
                .'Actual = signed SUM(project_cost_ledger). Payments / GRN / Tally are not cost.',
            'period_limited_actual' => $periodLimited,
            'period_note' => $periodLimited
                ? 'Date filter applies to Actual only. Estimated remains full-project planned cost.'
                : null,
            'totals' => [
                'estimated_cost' => $estTotal->toMoney(),
                'original_estimated_cost' => $origEstTotal->toMoney(),
                'variation_estimated_cost' => $varEstTotal->toMoney(),
                'actual_cost' => $actualTotal->toMoney(),
                'actual_linked' => $actLinked->toMoney(),
                'actual_unallocated' => $unallocatedTotal->toMoney(),
                'ledger_total' => $ledgerTotal->toMoney(),
                'variance' => $variance->toMoney(),
                'variance_percent' => Num::percent($variance, $estTotal),
                'utilization_percent' => Num::percent($actualTotal, $estTotal),
                'status' => $this->statusFlag($estTotal, $actualTotal),
                'reconciles' => $actualTotal->equals($ledgerTotal),
            ],
            'head_summary' => $headSummary,
            'unallocated' => [
                'total' => $unallocatedTotal->toMoney(),
                'by_head' => array_map(fn (Decimal $d) => $d->toMoney(), $unallocatedByHead),
                'has_unallocated' => $unallocatedTotal->isPositive() || $unallocatedTotal->isNegative(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Item workspace Estimated vs Actual block.
     *
     * @return array<string, mixed>
     */
    public function itemAnalysis(BoqItem $item): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol', 'section:id,code,name']);
        $projectId = (int) $item->boq->project_id;
        $companyId = (int) $item->boq->company_id;
        $uid = (string) $item->line_uid;

        $qty = Decimal::of($this->effectiveQty->forItem($item));
        $rate = Decimal::of((string) $item->cost_rate);
        $estimated = $qty->times($rate)->round(Decimal::MONEY_SCALE);

        $headEst = $this->estimateHeadsForItem($item);
        $actualBucket = $this->actualByLineUid($companyId, $projectId, null, null, null)[$uid]
            ?? ['total' => Decimal::zero(), 'heads' => array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero())];

        $actual = $actualBucket['total'];
        $variance = $estimated->minus($actual);

        $heads = [];
        foreach (BoqCostHeadMapper::DISPLAY_HEADS as $h) {
            $e = $headEst[$h] ?? Decimal::zero();
            $a = $actualBucket['heads'][$h] ?? Decimal::zero();
            $heads[] = [
                'cost_head' => $h,
                'label' => BoqCostHeadMapper::label($h),
                'estimated' => $e->toMoney(),
                'actual' => $a->toMoney(),
                'variance' => $e->minus($a)->toMoney(),
            ];
        }

        return [
            'boq_qty' => $qty->toQuantity(),
            'cost_rate' => $rate->toRate(),
            'estimated_cost' => $estimated->toMoney(),
            'actual_cost' => $actual->toMoney(),
            'variance' => $variance->toMoney(),
            'variance_percent' => Num::percent($variance, $estimated),
            'utilization_percent' => Num::percent($actual, $estimated),
            'status' => $this->statusFlag($estimated, $actual),
            'heads' => $heads,
            'line_uid' => $uid,
            'formula_note' => 'Estimated = Qty × Cost Rate. Actual = project_cost_ledger by line_uid (signed). Variance = Estimated − Actual.',
        ];
    }

    /**
     * Drill-down ledger rows for a line_uid or unallocated bucket.
     *
     * @return list<array<string, mixed>>
     */
    public function actualDrillDown(Project $project, ?string $lineUid = null, bool $unallocatedOnly = false, ?string $from = null, ?string $to = null): array
    {
        $q = ProjectCostEntry::query()
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')
            ->orderBy('id');

        if ($unallocatedOnly) {
            $q->whereNull('boq_line_uid')->whereNull('boq_item_id');
        } elseif ($lineUid !== null) {
            $q->where('boq_line_uid', $lineUid);
        }

        return $q->with(['boqItem:id,item_code,name', 'task:id,name'])->get()->map(function (ProjectCostEntry $e) {
            return [
                'id' => $e->id,
                'entry_date' => optional($e->entry_date)?->toDateString(),
                'cost_head' => $e->cost_head->value,
                'cost_head_label' => BoqCostHeadMapper::label(BoqCostHeadMapper::fromLedgerHead($e->cost_head)),
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'description' => $e->remarks,
                'boq_item' => $e->boqItem ? trim(($e->boqItem->item_code ?? '').' '.($e->boqItem->name ?? '')) : null,
                'line_uid' => $e->boq_line_uid,
                'task' => $e->task?->name,
                'amount' => Decimal::of((string) $e->amount)->toMoney(),
                'is_reversal' => (bool) $e->is_reversal,
            ];
        })->all();
    }

    /**
     * Compact widget for project overview (financial users only).
     *
     * @return array<string, mixed>
     */
    public function projectWidget(Project $project): array
    {
        $report = $this->projectReport($project);

        return [
            'estimated_cost' => $report['totals']['estimated_cost'],
            'actual_cost' => $report['totals']['actual_cost'],
            'variance' => $report['totals']['variance'],
            'variance_percent' => $report['totals']['variance_percent'],
            'utilization_percent' => $report['totals']['utilization_percent'],
            'status' => $report['totals']['status'],
            'has_unallocated' => $report['unallocated']['has_unallocated'],
            'report_url' => route('reports.project', [$project->id, 'estimated-vs-actual-cost']),
        ];
    }

    /**
     * @param  array{boq_section_id?: int|null, line_uid?: string|null, cost_head?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function estimateRows(Project $project, array $filters): array
    {
        $companyId = (int) $project->company_id;
        $projectId = (int) $project->id;
        $effective = $this->effectiveQty->mapForProject($project);

        $items = DB::table('boq_items as i')
            ->join('boqs as b', 'b.id', '=', 'i.boq_id')
            ->leftJoin('boq_sections as s', 's.id', '=', 'i.boq_section_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->where('b.company_id', $companyId)
            ->where('b.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->where('b.status', '!=', 'revised')
            ->when($filters['boq_section_id'] ?? null, fn ($q, $id) => $q->where('i.boq_section_id', $id))
            ->when($filters['line_uid'] ?? null, fn ($q, $uid) => $q->where('i.line_uid', $uid))
            ->orderBy('s.sort_order')
            ->orderBy('i.sort_order')
            ->orderBy('i.id')
            ->select([
                'i.id as boq_item_id',
                'i.line_uid',
                'i.item_code',
                'i.name as item_name',
                'i.quantity',
                'i.quantity_source',
                'i.cost_rate',
                'i.selling_rate',
                'i.client_rate',
                'i.material_rate',
                'i.labour_rate',
                'i.equipment_rate',
                'i.subcontract_rate',
                'i.is_variation_originated',
                'i.boq_section_id',
                'b.id as boq_id',
                'b.boq_number',
                's.code as section_code',
                's.name as section_name',
                'u.symbol as unit',
            ])
            ->get();

        $itemIds = $items->pluck('boq_item_id')->all();
        $components = $itemIds === []
            ? collect()
            : DB::table('boq_cost_components')
                ->whereIn('boq_item_id', $itemIds)
                ->select(['boq_item_id', 'component_type', 'amount_per_boq_unit'])
                ->get()
                ->groupBy('boq_item_id');

        $rows = [];
        foreach ($items as $item) {
            $uid = (string) $item->line_uid;
            $originalQty = Decimal::of((string) $item->quantity);
            $qty = Decimal::of($effective[$uid] ?? (string) $item->quantity);
            $rate = Decimal::of((string) $item->cost_rate);
            $estimated = $qty->times($rate)->round(Decimal::MONEY_SCALE);
            $originalEstimate = $item->is_variation_originated
                ? Decimal::zero()
                : $originalQty->times($rate)->round(Decimal::MONEY_SCALE);
            $variationEstimate = $estimated->minus($originalEstimate);

            $heads = array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());
            $comps = $components->get($item->boq_item_id, collect());
            if ($comps->isNotEmpty()) {
                foreach ($comps as $c) {
                    $h = BoqCostHeadMapper::fromComponentType((string) $c->component_type);
                    $heads[$h] = $heads[$h]->plus(
                        $qty->times((string) $c->amount_per_boq_unit)->round(Decimal::MONEY_SCALE)
                    );
                }
            } else {
                // Fallback to item rate snapshot columns (subcontract_rate may include Other).
                $heads['material'] = $qty->times((string) $item->material_rate)->round(Decimal::MONEY_SCALE);
                $heads['labour'] = $qty->times((string) $item->labour_rate)->round(Decimal::MONEY_SCALE);
                $heads['equipment'] = $qty->times((string) $item->equipment_rate)->round(Decimal::MONEY_SCALE);
                $heads['subcontract'] = $qty->times((string) $item->subcontract_rate)->round(Decimal::MONEY_SCALE);
            }

            if (($filters['cost_head'] ?? null) && ($heads[$filters['cost_head']] ?? Decimal::zero())->isZero() && $estimated->isZero()) {
                continue;
            }

            $rows[] = [
                'boq_id' => (int) $item->boq_id,
                'boq_number' => $item->boq_number,
                'boq_section_id' => $item->boq_section_id ? (int) $item->boq_section_id : null,
                'section_code' => $item->section_code,
                'section_name' => $item->section_name,
                'boq_item_id' => (int) $item->boq_item_id,
                'line_uid' => $uid,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'quantity_source' => $item->quantity_source,
                'is_variation_originated' => (bool) $item->is_variation_originated,
                'boq_qty' => $qty,
                'original_qty' => $originalQty,
                'selling_rate' => Decimal::of((string) ($item->client_rate ?: $item->selling_rate ?: '0')),
                'cost_rate' => $rate,
                'estimated_cost' => $estimated,
                'original_estimated_cost' => $originalEstimate,
                'variation_estimated_cost' => $variationEstimate,
                'heads' => $heads,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{total: Decimal, heads: array<string, Decimal>, boq_item_id?: int|null, item_code?: ?string, item_name?: ?string}>
     */
    private function actualByLineUid(int $companyId, int $projectId, ?string $from, ?string $to, ?string $displayHead): array
    {
        $q = DB::table('project_cost_ledger as l')
            ->leftJoin('boq_items as i', 'i.id', '=', 'l.boq_item_id')
            ->where('l.company_id', $companyId)
            ->where('l.project_id', $projectId)
            ->whereNotNull('l.boq_line_uid')
            ->when($from, fn ($q) => $q->where('l.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.entry_date', '<=', $to))
            ->groupBy('l.boq_line_uid', 'l.cost_head')
            ->selectRaw('l.boq_line_uid, l.cost_head, SUM(l.amount) as total, MAX(l.boq_item_id) as boq_item_id, MAX(i.item_code) as item_code, MAX(i.name) as item_name');

        $out = [];
        foreach ($q->get() as $row) {
            $uid = (string) $row->boq_line_uid;
            $h = BoqCostHeadMapper::fromLedgerHead((string) $row->cost_head);
            if ($displayHead && $h !== $displayHead) {
                continue;
            }
            if (! isset($out[$uid])) {
                $out[$uid] = [
                    'total' => Decimal::zero(),
                    'heads' => array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero()),
                    'boq_item_id' => $row->boq_item_id ? (int) $row->boq_item_id : null,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                ];
            }
            $amt = Num::dec($row->total);
            $out[$uid]['heads'][$h] = $out[$uid]['heads'][$h]->plus($amt);
            $out[$uid]['total'] = $out[$uid]['total']->plus($amt);
        }

        // Also include rows linked only by boq_item_id (legacy) without line_uid — attach via item.line_uid.
        $legacy = DB::table('project_cost_ledger as l')
            ->join('boq_items as i', 'i.id', '=', 'l.boq_item_id')
            ->where('l.company_id', $companyId)
            ->where('l.project_id', $projectId)
            ->whereNull('l.boq_line_uid')
            ->whereNotNull('l.boq_item_id')
            ->when($from, fn ($q) => $q->where('l.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.entry_date', '<=', $to))
            ->groupBy('i.line_uid', 'l.cost_head')
            ->selectRaw('i.line_uid as boq_line_uid, l.cost_head, SUM(l.amount) as total, MAX(l.boq_item_id) as boq_item_id, MAX(i.item_code) as item_code, MAX(i.name) as item_name')
            ->get();

        foreach ($legacy as $row) {
            $uid = (string) $row->boq_line_uid;
            $h = BoqCostHeadMapper::fromLedgerHead((string) $row->cost_head);
            if ($displayHead && $h !== $displayHead) {
                continue;
            }
            if (! isset($out[$uid])) {
                $out[$uid] = [
                    'total' => Decimal::zero(),
                    'heads' => array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero()),
                    'boq_item_id' => $row->boq_item_id ? (int) $row->boq_item_id : null,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                ];
            }
            $amt = Num::dec($row->total);
            $out[$uid]['heads'][$h] = $out[$uid]['heads'][$h]->plus($amt);
            $out[$uid]['total'] = $out[$uid]['total']->plus($amt);
        }

        return $out;
    }

    /**
     * @return array<string, Decimal>
     */
    private function unallocatedByHead(int $companyId, int $projectId, ?string $from, ?string $to): array
    {
        $out = array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());
        DB::table('project_cost_ledger as l')
            ->where('l.company_id', $companyId)
            ->where('l.project_id', $projectId)
            ->whereNull('l.boq_line_uid')
            ->whereNull('l.boq_item_id')
            ->when($from, fn ($q) => $q->where('l.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('l.entry_date', '<=', $to))
            ->groupBy('l.cost_head')
            ->selectRaw('l.cost_head, SUM(l.amount) as total')
            ->get()
            ->each(function ($row) use (&$out) {
                $h = BoqCostHeadMapper::fromLedgerHead((string) $row->cost_head);
                $out[$h] = $out[$h]->plus(Num::dec($row->total));
            });

        return $out;
    }

    private function ledgerTotal(int $companyId, int $projectId, ?string $from, ?string $to): Decimal
    {
        $sum = DB::table('project_cost_ledger')
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->sum('amount');

        return Num::dec($sum);
    }

    /**
     * @return array<string, Decimal>
     */
    private function estimateHeadsForItem(BoqItem $item): array
    {
        $heads = array_fill_keys(BoqCostHeadMapper::DISPLAY_HEADS, Decimal::zero());
        $qty = Decimal::of($this->effectiveQty->forItem($item));
        $comps = DB::table('boq_cost_components')
            ->where('boq_item_id', $item->id)
            ->get(['component_type', 'amount_per_boq_unit']);

        if ($comps->isNotEmpty()) {
            foreach ($comps as $c) {
                $h = BoqCostHeadMapper::fromComponentType((string) $c->component_type);
                $heads[$h] = $heads[$h]->plus($qty->times((string) $c->amount_per_boq_unit)->round(Decimal::MONEY_SCALE));
            }

            return $heads;
        }

        $heads['material'] = $qty->times((string) $item->material_rate)->round(Decimal::MONEY_SCALE);
        $heads['labour'] = $qty->times((string) $item->labour_rate)->round(Decimal::MONEY_SCALE);
        $heads['equipment'] = $qty->times((string) $item->equipment_rate)->round(Decimal::MONEY_SCALE);
        $heads['subcontract'] = $qty->times((string) $item->subcontract_rate)->round(Decimal::MONEY_SCALE);

        return $heads;
    }

    private function statusFlag(Decimal $estimated, Decimal $actual): string
    {
        if ($actual->greaterThan($estimated)) {
            return 'over';
        }
        if ($estimated->isPositive()) {
            $util = $actual->dividedBy($estimated)->times(100)->round(2);
            if ($util->greaterThanOrEqual('90')) {
                return 'near';
            }
        }

        return 'under';
    }
}
