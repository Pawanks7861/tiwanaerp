<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\CostComponentType;
use App\Enums\Inventory\StockTxnType;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\MaterialRequestStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqItemMaterialCost;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Material;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Projects\Project;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Cache\ErpCache;
use App\Support\Cache\ErpCacheKey;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material Control — Estimate vs Actual (read-only).
 *
 * Chain: Advanced BOQ costing / BBS (or legacy simple materials) → Budget → MR → PO → GRN
 * → Stock Ledger Issue/Return → Net Consumption → Variance.
 *
 * Actual cost uses inventory ledger valuation (issue_out − return_in values), never Material Master price.
 * Zero side effects. Aggregate report payloads may be cache-aside cached; stock validation never uses this cache.
 */
class ProjectMaterialControlService
{
    public function __construct(
        private readonly MaterialReconciliationService $reconciliation,
        private readonly BoqBudgetMaterialSyncService $budgetSync,
        private readonly UnitConversionService $conversions,
        private readonly ErpCache $cache,
    ) {}

    /**
     * @param  array{
     *   material_id?: int|null,
     *   category_id?: int|null,
     *   status?: string|null,
     *   search?: string|null,
     *   budget_version_id?: int|null,
     *   boq_id?: int|null,
     *   over_consumption?: bool|null,
     *   unestimated_only?: bool|null,
     *   as_of_date?: string|null,
     *   page?: int|null,
     *   per_page?: int|null
     * }  $filters
     * @return array{
     *   summary: array<string, mixed>,
     *   rows: list<array<string, mixed>>,
     *   pagination: array{page: int, per_page: int, total: int, last_page: int},
     *   meta: array<string, mixed>,
     *   formula_notes: array<string, string>
     * }
     */
    public function report(Project $project, array $filters = []): array
    {
        $companyId = (int) $project->company_id;
        $projectId = (int) $project->id;
        $normalized = ErpCacheKey::normalize($filters);
        $filterHash = ErpCacheKey::filterHash($normalized);

        return $this->cache->remember(
            'material_control',
            $companyId,
            ErpCacheKey::materialControl($companyId, $projectId, $filterHash),
            ['project' => $projectId, 'filters' => $normalized],
            fn () => $this->buildReport($project, $filters),
            lock: true,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildReport(Project $project, array $filters = []): array
    {
        $asOf = $this->asOfDate($filters['as_of_date'] ?? null);
        $budget = $this->resolveBudget($project, isset($filters['budget_version_id']) ? (int) $filters['budget_version_id'] : null);
        $boq = $this->resolveBoq($project, isset($filters['boq_id']) ? (int) $filters['boq_id'] : null, $budget);
        $advanced = BoqCompanySettings::enabled($project->company);
        $syncStatus = $this->budgetSync->syncStatus($project, $boq);

        $estimates = $advanced
            ? $this->advancedEstimates($project, $boq)
            : $this->legacyEstimates($project, $boq);
        $budgets = $this->budgetQuantities($budget);
        $opsIds = $this->operationalMaterialIds($project->id, $asOf);

        $materialIds = collect($estimates)->keys()
            ->merge(collect($budgets)->keys())
            ->merge($opsIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (isset($filters['material_id']) && $filters['material_id']) {
            $materialIds = array_values(array_intersect($materialIds, [(int) $filters['material_id']]));
        }

        $materials = $materialIds === []
            ? collect()
            : Material::query()->whereIn('id', $materialIds)->with('unit:id,symbol')->get()->keyBy('id');

        if (! empty($filters['category_id'])) {
            $cat = (int) $filters['category_id'];
            $materialIds = $materials->filter(fn (Material $m) => (int) $m->material_category_id === $cat)->keys()->map(fn ($id) => (int) $id)->values()->all();
            $materials = $materials->only($materialIds);
        }

        $proc = $this->procurementAggregates($project->id, $materialIds);
        $ledger = $this->ledgerAggregates($project->id, $materialIds, $asOf);

        $rows = [];
        foreach ($materialIds as $mid) {
            $material = $materials->get($mid);
            if ($material === null) {
                continue;
            }

            $est = $estimates[$mid] ?? null;
            $bud = $budgets[$mid] ?? null;
            $unit = $est['unit'] ?? $bud['unit'] ?? ($material->unit?->symbol ?: 'Nos');

            $estimatedQty = (string) ($est['quantity'] ?? '0.0000');
            $estimatedAmount = (string) ($est['amount'] ?? '0.00');
            $estimatedRate = Decimal::of($estimatedQty)->isZero()
                ? '0.0000'
                : Decimal::of($estimatedAmount)->dividedBy($estimatedQty)->toRate();

            $budgetQty = (string) ($bud['quantity'] ?? '0.0000');
            $budgetAmount = (string) ($bud['amount'] ?? '0.00');

            $mr = $proc['mr'][$mid] ?? '0.0000';
            $po = $proc['po'][$mid] ?? '0.0000';
            $grn = $proc['grn'][$mid] ?? '0.0000';
            $issued = $ledger['issued_qty'][$mid] ?? '0.0000';
            $returned = $ledger['returned_qty'][$mid] ?? '0.0000';
            $transferOut = $ledger['transfer_out_qty'][$mid] ?? '0.0000';
            $transferIn = $ledger['transfer_in_qty'][$mid] ?? '0.0000';
            $net = Decimal::of($issued)->minus($returned)->toQuantity();
            // Project-scoped warehouse transfers keep the same project_id; they do not change net consumption.
            // Expose transfer qtys for drilldown; net consumption remains Issue − Return (ledger truth).

            $actualCost = Decimal::of($ledger['issued_value'][$mid] ?? '0')
                ->minus($ledger['returned_value'][$mid] ?? '0')
                ->toMoney();
            $actualAvg = Decimal::of($net)->isZero() || Decimal::of($net)->isNegative()
                ? null
                : Decimal::of($actualCost)->dividedBy($net)->toRate();

            $remaining = Decimal::of($estimatedQty)->minus($net)->toQuantity();
            $varianceQty = Decimal::of($net)->minus($estimatedQty)->toQuantity();
            $variancePct = null;
            $unestimated = Decimal::of($estimatedQty)->isZero() && Decimal::of($net)->isPositive();
            if (! Decimal::of($estimatedQty)->isZero()) {
                $variancePct = Num::percent($varianceQty, $estimatedQty);
            }

            $costVariance = Decimal::of($actualCost)->minus($estimatedAmount)->toMoney();

            $status = $this->status(
                estimatedQty: $estimatedQty,
                budgetQty: $budgetQty,
                net: $net,
                syncRequired: (bool) ($syncStatus['materials_sync_pending'] ?? false)
                    || in_array($syncStatus['status'] ?? '', ['sync_required', 'blocked_by_approved'], true),
                hasEstimate: $est !== null,
                hasBudget: $bud !== null,
                budgetSource: $bud['source'] ?? null,
            );

            $row = [
                'material_id' => $mid,
                'material_code' => $material->code,
                'material_name' => $material->name,
                'unit' => $unit,
                'estimate_source' => $est['source'] ?? ($advanced ? 'advanced_boq' : 'legacy'),
                'estimated_qty' => $estimatedQty,
                'budget_qty' => $budgetQty,
                'budget_version' => $budget?->version,
                'budget_id' => $budget?->id,
                'budget_source' => $bud['source'] ?? null,
                'mr_qty' => $mr,
                'po_qty' => $po,
                'grn_qty' => $grn,
                'issued_qty' => $issued,
                'returned_qty' => $returned,
                'transfer_out_qty' => $transferOut,
                'transfer_in_qty' => $transferIn,
                'net_consumed_qty' => $net,
                'remaining_estimate' => $remaining,
                'variance_qty' => $varianceQty,
                'variance_percent' => $variancePct,
                'variance_percent_label' => $unestimated ? 'Unbudgeted / Unestimated Consumption' : $variancePct,
                'estimated_rate' => $estimatedRate,
                'estimated_cost' => $estimatedAmount,
                'budget_cost' => $budgetAmount,
                'actual_avg_rate' => $actualAvg,
                'actual_consumption_cost' => $actualCost,
                'cost_variance' => $costVariance,
                'pending_mr' => Decimal::of($estimatedQty)->minus($mr)->toQuantity(),
                'pending_po' => Decimal::of($mr)->minus($po)->toQuantity(),
                'pending_receipt' => Decimal::of($po)->minus($grn)->toQuantity(),
                'status' => $status,
                'alerts' => $this->alerts($estimatedQty, $net, $estimatedAmount, $actualCost, $unestimated),
                'as_of_date' => $asOf,
                'estimate_scope' => 'project_total',
                'actual_scope' => 'cumulative_to_date',
            ];

            if ($this->passesFilters($row, $filters)) {
                $rows[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['material_name'], $b['material_name']));

        $summary = $this->summary($rows, $syncStatus, $budget, $boq);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 30)));
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'summary' => $summary,
            'rows' => $slice,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'meta' => [
                'advanced' => $advanced,
                'boq_id' => $boq?->id,
                'boq_number' => $boq?->boq_number,
                'boq_version' => $boq?->version,
                'budget_id' => $budget?->id,
                'budget_version' => $budget?->version,
                'budget_status' => $budget?->status?->value,
                'sync_status' => $syncStatus,
                'as_of_date' => $asOf,
                'estimate_label' => 'Project total estimate (not periodized)',
                'actual_label' => 'Cumulative actuals to '.$asOf,
            ],
            'formula_notes' => $this->formulaNotes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Project $project, int $materialId, array $filters = []): array
    {
        $report = $this->report($project, array_merge($filters, [
            'material_id' => $materialId,
            'page' => 1,
            'per_page' => 1,
        ]));
        $row = $report['rows'][0] ?? null;
        $asOf = $this->asOfDate($filters['as_of_date'] ?? null);
        $budget = $this->resolveBudget($project, isset($filters['budget_version_id']) ? (int) $filters['budget_version_id'] : null);
        $boq = $this->resolveBoq($project, isset($filters['boq_id']) ? (int) $filters['boq_id'] : null, $budget);
        $advanced = BoqCompanySettings::enabled($project->company);

        return [
            'summary' => $row,
            'meta' => $report['meta'],
            'estimate_sources' => $advanced
                ? $this->advancedEstimateDrilldown($project, $boq, $materialId)
                : $this->legacyEstimateDrilldown($project, $boq, $materialId),
            'budget_lines' => $this->budgetDrilldown($budget, $materialId),
            'documents' => [
                'mr' => $this->reconciliation->sourceDocuments($project, $materialId, 'mr'),
                'po' => $this->reconciliation->sourceDocuments($project, $materialId, 'po'),
                'grn' => $this->reconciliation->sourceDocuments($project, $materialId, 'grn'),
                'issue' => $this->reconciliation->sourceDocuments($project, $materialId, 'issue'),
                'return' => $this->reconciliation->sourceDocuments($project, $materialId, 'return'),
            ],
            'ledger' => $this->ledgerDrilldown($project->id, $materialId, $asOf),
            'formula_notes' => $this->formulaNotes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formulaNotes(): array
    {
        return [
            'estimate' => 'Advanced: active material cost components linked to Material Master (source_id) + approved BBS mapped steel. Simplified: legacy BOQ material rows with material_id. Commercial client rate is never used.',
            'bbs' => 'Approved BBS is authoritative for steel; steel-like cost components on BBS items are excluded (same rule as Budget sync).',
            'budget' => 'Active / selected Project Budget material lines with material_id. Manual budget rows are labelled Manual Budget.',
            'net_consumption' => 'Net Consumption = Issue Out − Return In (project stock ledger). Warehouse transfers on the same project do not change net consumption.',
            'variance_qty' => 'Variance Qty = Net Consumed − Estimated. Positive = Over Consumption; Negative = Under Consumption.',
            'variance_pct' => 'Variance % = Variance Qty / Estimated Qty × 100. When Estimate = 0 and actual > 0: Unbudgeted / Unestimated Consumption.',
            'actual_cost' => 'Actual Cost = Σ issue_out value − Σ return_in value (weighted average valuation). Never Material Master standard rate.',
            'cost_variance' => 'Cost Variance = Actual Consumption Cost − Estimated Cost.',
            'pending' => 'Pending MR = Estimate − MR; Pending PO = MR − PO; Pending Receipt = PO − GRN (negatives expose excess).',
            'date' => 'Estimate/Budget are project totals. Actuals are cumulative to as_of_date (default today).',
            'side_effects' => 'Read-only: opening/export creates zero MR/PO/GRN/stock/budget/finance rows.',
        ];
    }

    /**
     * Redact cost fields for users without cost permission.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactCosts(array $payload): array
    {
        $costKeys = [
            'estimated_rate', 'estimated_cost', 'budget_cost', 'actual_avg_rate',
            'actual_consumption_cost', 'cost_variance',
        ];
        $summaryKeys = [
            'estimated_material_cost', 'budgeted_material_cost', 'actual_material_consumption_cost', 'cost_variance',
        ];

        if (isset($payload['summary']) && is_array($payload['summary'])) {
            foreach ($summaryKeys as $key) {
                unset($payload['summary'][$key]);
            }
            foreach ($costKeys as $key) {
                unset($payload['summary'][$key]);
            }
        }

        if (isset($payload['rows']) && is_array($payload['rows'])) {
            $payload['rows'] = array_map(function (array $row) use ($costKeys) {
                foreach ($costKeys as $key) {
                    unset($row[$key]);
                }

                return $row;
            }, $payload['rows']);
        }

        return $payload;
    }

    private function asOfDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['as_of_date' => 'Enter a valid date.']);
        }
    }

    private function resolveBudget(Project $project, ?int $budgetId): ?ProjectBudget
    {
        // Material Control uses BOQ Estimate quantities/costs — never Main Project Budget.
        if ($budgetId !== null) {
            return ProjectBudget::query()
                ->where('project_id', $project->id)
                ->boqEstimate()
                ->whereKey($budgetId)
                ->first();
        }

        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Approved)
            ->orderByDesc('version')
            ->first();
        if ($approved) {
            return $approved;
        }

        return ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Draft)
            ->orderByDesc('version')
            ->first();
    }

    private function resolveBoq(Project $project, ?int $boqId, ?ProjectBudget $budget): ?Boq
    {
        if ($boqId !== null) {
            return Boq::query()->where('project_id', $project->id)->whereKey($boqId)->first();
        }

        if ($budget?->boq_id) {
            $linked = Boq::query()->where('project_id', $project->id)->whereKey($budget->boq_id)->first();
            if ($linked) {
                return $linked;
            }
        }

        return Boq::query()
            ->where('project_id', $project->id)
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array<int, array{quantity: string, amount: string, unit: string, source: string}>
     */
    private function advancedEstimates(Project $project, ?Boq $boq): array
    {
        if ($boq === null) {
            return [];
        }

        $bbsItemIds = BoqBbs::query()
            ->where('boq_id', $boq->id)
            ->where('status', BbsStatus::Approved)
            ->pluck('boq_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        /** @var array<int, array{quantity: Decimal, amount: Decimal, unit: string, source: string}> $groups */
        $groups = [];

        $components = BoqCostComponent::query()
            ->where('boq_id', $boq->id)
            ->where('component_type', CostComponentType::Material)
            ->where('is_active', true)
            ->where('source_type', Material::class)
            ->whereNotNull('source_id')
            ->with(['item:id,quantity,line_uid,item_code,name'])
            ->get();

        foreach ($components as $component) {
            $mid = (int) $component->source_id;
            $item = $component->item;
            if ($item === null || $mid < 1) {
                continue;
            }
            if (isset($bbsItemIds[(int) $item->id]) && $this->looksLikeSteel((string) $component->description)) {
                continue;
            }

            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $wastage = Decimal::of((string) ($component->wastage_percent ?? '0'));
            $factor = Decimal::of('1')->plus($wastage->dividedBy('100'));
            $netQty = Decimal::of((string) $component->consumption_qty)->times($factor)->times($boqQty)->toQuantity();
            $perUnit = Decimal::of((string) $component->amount_per_boq_unit);
            $rate = Decimal::of((string) $component->rate)->toRate();
            $amount = $perUnit->isPositive()
                ? Decimal::of($perUnit)->times($boqQty)->toMoney()
                : Decimal::of($netQty)->times($rate)->toMoney();
            $unit = trim((string) ($component->consumption_unit ?: 'Nos'));

            if (! isset($groups[$mid])) {
                $groups[$mid] = [
                    'quantity' => Decimal::zero(),
                    'amount' => Decimal::zero(),
                    'unit' => $unit,
                    'source' => 'advanced_boq',
                ];
            } else {
                $converted = $this->tryConvert(
                    (int) $project->company_id,
                    (int) $project->id,
                    $mid,
                    $unit,
                    $groups[$mid]['unit'],
                    $netQty,
                );
                if ($converted === null && mb_strtolower($unit) !== mb_strtolower($groups[$mid]['unit'])) {
                    // Incompatible units: keep first unit row only for this material_id aggregate key.
                    // Spec: never silently aggregate incompatible units — skip this component into same bucket.
                    continue;
                }
                if ($converted !== null) {
                    $netQty = $converted;
                }
            }

            $groups[$mid]['quantity'] = Decimal::of($groups[$mid]['quantity'])->plus($netQty);
            $groups[$mid]['amount'] = Decimal::of($groups[$mid]['amount'])->plus($amount);
        }

        foreach ($this->bbsEstimateGroups($boq) as $mid => $group) {
            if (! isset($groups[$mid])) {
                $groups[$mid] = $group;
            } else {
                $converted = $this->tryConvert(
                    (int) $project->company_id,
                    (int) $project->id,
                    $mid,
                    $group['unit'],
                    $groups[$mid]['unit'],
                    Decimal::of($group['quantity'])->toQuantity(),
                );
                if ($converted === null && mb_strtolower($group['unit']) !== mb_strtolower($groups[$mid]['unit'])) {
                    continue;
                }
                $qty = $converted ?? $group['quantity'];
                $groups[$mid]['quantity'] = Decimal::of($groups[$mid]['quantity'])->plus($qty);
                $groups[$mid]['amount'] = Decimal::of($groups[$mid]['amount'])->plus($group['amount']);
            }
        }

        $out = [];
        foreach ($groups as $mid => $g) {
            $out[$mid] = [
                'quantity' => Decimal::of($g['quantity'])->toQuantity(),
                'amount' => Decimal::of($g['amount'])->toMoney(),
                'unit' => $g['unit'],
                'source' => $g['source'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{quantity: Decimal, amount: Decimal, unit: string, source: string}>
     */
    private function bbsEstimateGroups(Boq $boq): array
    {
        $bbsList = BoqBbs::query()
            ->where('boq_id', $boq->id)
            ->where('status', BbsStatus::Approved)
            ->with(['lines', 'steelMaps.material:id,code,name,standard_rate', 'item:id'])
            ->get();

        $groups = [];
        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            $maps = $bbs->steelMaps->keyBy(fn ($m) => (int) $m->diameter_mm);

            $steelRateComponent = BoqCostComponent::query()
                ->where('boq_item_id', $bbs->boq_item_id)
                ->where('component_type', CostComponentType::Material)
                ->where('is_active', true)
                ->get()
                ->first(fn (BoqCostComponent $c) => $this->looksLikeSteel((string) $c->description));

            foreach ($summary as $row) {
                $map = $maps->get($row['diameter_mm']);
                if ($map?->material_id === null) {
                    continue;
                }
                $mid = (int) $map->material_id;
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
                    } else {
                        $rate = $compRate->toRate();
                    }
                } elseif ($map->material?->standard_rate !== null
                    && Decimal::of((string) $map->material->standard_rate)->isPositive()) {
                    // Snapshot from material at BBS map time is not stored; prefer component rate.
                    // Do not use live master for estimated cost when a component exists; here only as last resort for qty report with zero amount.
                    $rate = '0.0000';
                }
                $amount = Decimal::of($netQty)->times($rate)->toMoney();

                if (! isset($groups[$mid])) {
                    $groups[$mid] = [
                        'quantity' => Decimal::zero(),
                        'amount' => Decimal::zero(),
                        'unit' => $unit,
                        'source' => 'advanced_boq_bbs',
                    ];
                }
                $groups[$mid]['quantity'] = Decimal::of($groups[$mid]['quantity'])->plus($netQty);
                $groups[$mid]['amount'] = Decimal::of($groups[$mid]['amount'])->plus($amount);
            }
        }

        return $groups;
    }

    /**
     * @return array<int, array{quantity: string, amount: string, unit: string, source: string}>
     */
    private function legacyEstimates(Project $project, ?Boq $boq): array
    {
        if ($boq === null) {
            return [];
        }

        $rows = BoqItemMaterialCost::query()
            ->where('boq_id', $boq->id)
            ->whereNotNull('material_id')
            ->with(['item:id,quantity'])
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $mid = (int) $row->material_id;
            $item = $row->item;
            if ($item === null) {
                continue;
            }
            $boqQty = Decimal::of((string) $item->quantity)->toQuantity();
            $qty = Decimal::of((string) $row->quantity)->times($boqQty)->toQuantity();
            $rate = Decimal::of((string) $row->rate)->toRate();
            $amount = Decimal::of($qty)->times($rate)->toMoney();
            $unit = trim((string) $row->unit) ?: 'Nos';

            if (! isset($groups[$mid])) {
                $groups[$mid] = [
                    'quantity' => Decimal::zero(),
                    'amount' => Decimal::zero(),
                    'unit' => $unit,
                    'source' => 'legacy_boq_material',
                ];
            }
            $groups[$mid]['quantity'] = Decimal::of($groups[$mid]['quantity'])->plus($qty);
            $groups[$mid]['amount'] = Decimal::of($groups[$mid]['amount'])->plus($amount);
        }

        $out = [];
        foreach ($groups as $mid => $g) {
            $out[$mid] = [
                'quantity' => Decimal::of($g['quantity'])->toQuantity(),
                'amount' => Decimal::of($g['amount'])->toMoney(),
                'unit' => $g['unit'],
                'source' => $g['source'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{quantity: string, amount: string, unit: string, source: string}>
     */
    private function budgetQuantities(?ProjectBudget $budget): array
    {
        if ($budget === null) {
            return [];
        }

        $lines = ProjectBudgetLine::query()
            ->where('project_budget_id', $budget->id)
            ->whereIn('source_type', [BudgetLineSource::BoqMaterial->value, BudgetLineSource::Manual->value])
            ->whereNotNull('material_id')
            ->get();

        $groups = [];
        foreach ($lines as $line) {
            $mid = (int) $line->material_id;
            $isManual = $line->source_type === BudgetLineSource::Manual;
            if (! isset($groups[$mid])) {
                $groups[$mid] = [
                    'quantity' => Decimal::zero(),
                    'amount' => Decimal::zero(),
                    'unit' => (string) ($line->unit ?: 'Nos'),
                    'source' => $isManual ? 'manual_budget' : 'budget',
                    'has_boq' => ! $isManual,
                    'has_manual' => $isManual,
                ];
            } else {
                if ($isManual) {
                    $groups[$mid]['has_manual'] = true;
                } else {
                    $groups[$mid]['has_boq'] = true;
                }
                $groups[$mid]['source'] = $groups[$mid]['has_boq']
                    ? ($groups[$mid]['has_manual'] ? 'budget_and_manual' : 'budget')
                    : 'manual_budget';
            }
            $groups[$mid]['quantity'] = Decimal::of($groups[$mid]['quantity'])->plus((string) ($line->quantity ?? '0'));
            $groups[$mid]['amount'] = Decimal::of($groups[$mid]['amount'])->plus((string) $line->amount);
            if ($line->unit) {
                $groups[$mid]['unit'] = (string) $line->unit;
            }
        }

        $out = [];
        foreach ($groups as $mid => $g) {
            $out[$mid] = [
                'quantity' => Decimal::of($g['quantity'])->toQuantity(),
                'amount' => Decimal::of($g['amount'])->toMoney(),
                'unit' => $g['unit'],
                'source' => $g['source'],
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function operationalMaterialIds(int $projectId, string $asOf): array
    {
        $mr = MaterialRequestItem::query()
            ->join('material_requests as mr', 'mr.id', '=', 'material_request_items.material_request_id')
            ->where('mr.project_id', $projectId)
            ->whereIn('mr.status', $this->mrStatuses())
            ->distinct()
            ->pluck('material_request_items.material_id');

        $po = PurchaseOrderItem::query()
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('po.project_id', $projectId)
            ->whereIn('po.status', $this->poStatuses())
            ->distinct()
            ->pluck('purchase_order_items.material_id');

        $grn = GrnItem::query()
            ->join('grns as g', 'g.id', '=', 'grn_items.grn_id')
            ->where('g.project_id', $projectId)
            ->where('g.status', GrnStatus::Approved->value)
            ->distinct()
            ->pluck('grn_items.material_id');

        $stock = StockTransaction::query()
            ->where('project_id', $projectId)
            ->whereDate('txn_date', '<=', $asOf)
            ->whereIn('txn_type', [
                StockTxnType::IssueOut->value,
                StockTxnType::ReturnIn->value,
                StockTxnType::TransferOut->value,
                StockTxnType::TransferIn->value,
            ])
            ->distinct()
            ->pluck('material_id');

        return $mr->merge($po)->merge($grn)->merge($stock)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{mr: array<int, string>, po: array<int, string>, grn: array<int, string>}
     */
    private function procurementAggregates(int $projectId, array $materialIds): array
    {
        if ($materialIds === []) {
            return ['mr' => [], 'po' => [], 'grn' => []];
        }

        $mr = MaterialRequestItem::query()
            ->selectRaw('material_request_items.material_id, SUM(material_request_items.quantity) as qty')
            ->join('material_requests as mr', 'mr.id', '=', 'material_request_items.material_request_id')
            ->where('mr.project_id', $projectId)
            ->whereIn('mr.status', $this->mrStatuses())
            ->whereIn('material_request_items.material_id', $materialIds)
            ->groupBy('material_request_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $po = PurchaseOrderItem::query()
            ->selectRaw('purchase_order_items.material_id, SUM(purchase_order_items.quantity) as qty')
            ->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('po.project_id', $projectId)
            ->whereIn('po.status', $this->poStatuses())
            ->whereIn('purchase_order_items.material_id', $materialIds)
            ->groupBy('purchase_order_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        $grn = GrnItem::query()
            ->selectRaw('grn_items.material_id, SUM(grn_items.accepted_qty) as qty')
            ->join('grns as g', 'g.id', '=', 'grn_items.grn_id')
            ->where('g.project_id', $projectId)
            ->where('g.status', GrnStatus::Approved->value)
            ->whereIn('grn_items.material_id', $materialIds)
            ->groupBy('grn_items.material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($q) => Num::qty($q))
            ->all();

        return ['mr' => $mr, 'po' => $po, 'grn' => $grn];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array{
     *   issued_qty: array<int, string>,
     *   returned_qty: array<int, string>,
     *   transfer_out_qty: array<int, string>,
     *   transfer_in_qty: array<int, string>,
     *   issued_value: array<int, string>,
     *   returned_value: array<int, string>
     * }
     */
    private function ledgerAggregates(int $projectId, array $materialIds, string $asOf): array
    {
        $empty = [
            'issued_qty' => [], 'returned_qty' => [], 'transfer_out_qty' => [], 'transfer_in_qty' => [],
            'issued_value' => [], 'returned_value' => [],
        ];
        if ($materialIds === []) {
            return $empty;
        }

        $rows = StockTransaction::query()
            ->select([
                'material_id',
                'txn_type',
                DB::raw('SUM(qty_in) as qty_in'),
                DB::raw('SUM(qty_out) as qty_out'),
                DB::raw('SUM(value) as value_sum'),
            ])
            ->where('project_id', $projectId)
            ->whereDate('txn_date', '<=', $asOf)
            ->whereIn('material_id', $materialIds)
            ->whereIn('txn_type', [
                StockTxnType::IssueOut->value,
                StockTxnType::ReturnIn->value,
                StockTxnType::TransferOut->value,
                StockTxnType::TransferIn->value,
            ])
            ->groupBy('material_id', 'txn_type')
            ->get();

        $out = $empty;
        foreach ($rows as $row) {
            $mid = (int) $row->material_id;
            $type = $row->txn_type instanceof StockTxnType
                ? $row->txn_type->value
                : (string) $row->txn_type;
            if ($type === StockTxnType::IssueOut->value) {
                $out['issued_qty'][$mid] = Num::qty($row->qty_out);
                $out['issued_value'][$mid] = Num::money($row->value_sum);
            } elseif ($type === StockTxnType::ReturnIn->value) {
                $out['returned_qty'][$mid] = Num::qty($row->qty_in);
                $out['returned_value'][$mid] = Num::money($row->value_sum);
            } elseif ($type === StockTxnType::TransferOut->value) {
                $out['transfer_out_qty'][$mid] = Num::qty($row->qty_out);
            } elseif ($type === StockTxnType::TransferIn->value) {
                $out['transfer_in_qty'][$mid] = Num::qty($row->qty_in);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function mrStatuses(): array
    {
        return [
            MaterialRequestStatus::Approved->value,
            MaterialRequestStatus::PartiallyOrdered->value,
            MaterialRequestStatus::Ordered->value,
            MaterialRequestStatus::Received->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function poStatuses(): array
    {
        return [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
        ];
    }

    private function status(
        string $estimatedQty,
        string $budgetQty,
        string $net,
        bool $syncRequired,
        bool $hasEstimate,
        bool $hasBudget,
        ?string $budgetSource,
    ): string {
        if ($syncRequired && $hasEstimate && Decimal::of($budgetQty)->isZero()) {
            return 'Budget Sync Required';
        }
        if ($syncRequired && $hasEstimate) {
            return 'Budget Sync Required';
        }
        if (! $hasEstimate && Decimal::of($net)->isPositive()) {
            return 'Unestimated Material';
        }
        if ($hasBudget && $budgetSource === 'manual_budget' && ! $hasEstimate) {
            return 'No Consumption Yet';
        }
        if (Decimal::of($net)->isZero()) {
            return 'No Consumption Yet';
        }
        if (Decimal::of($estimatedQty)->isZero()) {
            return 'Unestimated Material';
        }

        $ratio = Decimal::of($net)->dividedBy($estimatedQty);
        if ($ratio->greaterThan('1')) {
            return 'Over Consumption';
        }
        if ($ratio->greaterThanOrEqual('0.9')) {
            return 'Near Limit';
        }

        return 'On Track';
    }

    /**
     * @return list<string>
     */
    private function alerts(string $estimatedQty, string $net, string $estimatedCost, string $actualCost, bool $unestimated): array
    {
        $alerts = [];
        if ($unestimated) {
            $alerts[] = 'unestimated_consumption';
        }
        if (! Decimal::of($estimatedQty)->isZero()) {
            $ratio = Decimal::of($net)->dividedBy($estimatedQty);
            if ($ratio->greaterThan('1')) {
                $alerts[] = 'consumed_over_100';
            } elseif ($ratio->greaterThanOrEqual('0.9')) {
                $alerts[] = 'consumed_over_90';
            }
        }
        if (Decimal::of($actualCost)->greaterThan($estimatedCost) && ! Decimal::of($estimatedCost)->isZero()) {
            $alerts[] = 'actual_cost_over_estimate';
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function passesFilters(array $row, array $filters): bool
    {
        if (! empty($filters['status']) && $row['status'] !== $filters['status']) {
            return false;
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower(trim((string) $filters['search']));
            $hay = mb_strtolower($row['material_code'].' '.$row['material_name']);
            if (! str_contains($hay, $needle)) {
                return false;
            }
        }
        if (! empty($filters['over_consumption']) && $row['status'] !== 'Over Consumption') {
            return false;
        }
        if (! empty($filters['unestimated_only']) && $row['status'] !== 'Unestimated Material') {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $syncStatus
     * @return array<string, mixed>
     */
    private function summary(array $rows, array $syncStatus, ?ProjectBudget $budget, ?Boq $boq): array
    {
        $estCost = Decimal::zero();
        $budCost = Decimal::zero();
        $actCost = Decimal::zero();
        $over = 0;
        $unestimated = 0;
        $pendingProc = 0;

        foreach ($rows as $row) {
            $estCost = $estCost->plus((string) ($row['estimated_cost'] ?? '0'));
            $budCost = $budCost->plus((string) ($row['budget_cost'] ?? '0'));
            $actCost = $actCost->plus((string) ($row['actual_consumption_cost'] ?? '0'));
            if ($row['status'] === 'Over Consumption') {
                $over++;
            }
            if ($row['status'] === 'Unestimated Material') {
                $unestimated++;
            }
            if (Decimal::of((string) $row['pending_receipt'])->isPositive()
                || Decimal::of((string) $row['pending_po'])->isPositive()
                || Decimal::of((string) $row['pending_mr'])->isPositive()) {
                $pendingProc++;
            }
        }

        return [
            'estimated_material_cost' => $estCost->toMoney(),
            'budgeted_material_cost' => $budCost->toMoney(),
            'actual_material_consumption_cost' => $actCost->toMoney(),
            'cost_variance' => $actCost->minus($estCost)->toMoney(),
            'estimated_material_lines' => count(array_filter($rows, fn ($r) => ! Decimal::of((string) $r['estimated_qty'])->isZero())),
            'over_consumed_materials' => $over,
            'unestimated_materials' => $unestimated,
            'pending_procurement' => $pendingProc,
            'budget_version' => $budget?->version,
            'boq_version' => $boq?->version,
            'sync_status' => $syncStatus['label'] ?? null,
            'sync_status_key' => $syncStatus['status'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advancedEstimateDrilldown(Project $project, ?Boq $boq, int $materialId): array
    {
        if ($boq === null) {
            return [];
        }

        $out = [];
        $components = BoqCostComponent::query()
            ->where('boq_id', $boq->id)
            ->where('component_type', CostComponentType::Material)
            ->where('is_active', true)
            ->where('source_type', Material::class)
            ->where('source_id', $materialId)
            ->with(['item:id,item_code,name,quantity,line_uid'])
            ->get();

        foreach ($components as $c) {
            $item = $c->item;
            $boqQty = Decimal::of((string) ($item?->quantity ?? '0'))->toQuantity();
            $wastage = Decimal::of((string) ($c->wastage_percent ?? '0'));
            $factor = Decimal::of('1')->plus($wastage->dividedBy('100'));
            $net = Decimal::of((string) $c->consumption_qty)->times($factor)->times($boqQty)->toQuantity();
            $out[] = [
                'type' => 'cost_component',
                'boq_id' => $boq->id,
                'boq_number' => $boq->boq_number,
                'boq_version' => $boq->version,
                'boq_item_id' => $c->boq_item_id,
                'boq_line_uid' => $item?->line_uid,
                'item_code' => $item?->item_code,
                'item_name' => $item?->name,
                'component_id' => $c->id,
                'description' => $c->description,
                'unit' => $c->consumption_unit,
                'estimated_qty' => $net,
                'rate' => Decimal::of((string) $c->rate)->toRate(),
                'amount' => Decimal::of((string) $c->amount_per_boq_unit)->times($boqQty)->toMoney(),
                'url' => route('projects.boqs.items.workspace', [$project, $boq, $item ?? $c->boq_item_id], absolute: false),
            ];
        }

        $bbsList = BoqBbs::query()
            ->where('boq_id', $boq->id)
            ->where('status', BbsStatus::Approved)
            ->whereHas('steelMaps', fn ($q) => $q->where('material_id', $materialId))
            ->with(['lines', 'steelMaps', 'item:id,item_code,name,line_uid'])
            ->get();

        foreach ($bbsList as $bbs) {
            $theoByDia = [];
            $lenByDia = [];
            foreach ($bbs->lines as $line) {
                $d = (int) $line->diameter_mm;
                $theoByDia[$d] = Decimal::of($theoByDia[$d] ?? '0')->plus((string) $line->theoretical_weight)->toQuantity();
                $lenByDia[$d] = Decimal::of($lenByDia[$d] ?? '0')->plus((string) $line->total_length)->toQuantity();
            }
            $summary = BbsCalculator::diameterSummary($theoByDia, $lenByDia, (string) $bbs->wastage_percent);
            foreach ($bbs->steelMaps->where('material_id', $materialId) as $map) {
                $row = collect($summary)->firstWhere('diameter_mm', (int) $map->diameter_mm);
                if (! $row) {
                    continue;
                }
                $out[] = [
                    'type' => 'bbs',
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'bbs_id' => $bbs->id,
                    'boq_item_id' => $bbs->boq_item_id,
                    'item_code' => $bbs->item?->item_code,
                    'item_name' => $bbs->item?->name,
                    'diameter_mm' => (int) $map->diameter_mm,
                    'unit' => 'Kg',
                    'estimated_qty' => $row['required_weight'],
                    'url' => route('projects.boqs.items.workspace', [$project, $boq, $bbs->item ?? $bbs->boq_item_id], absolute: false),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyEstimateDrilldown(Project $project, ?Boq $boq, int $materialId): array
    {
        if ($boq === null) {
            return [];
        }

        return BoqItemMaterialCost::query()
            ->where('boq_id', $boq->id)
            ->where('material_id', $materialId)
            ->with(['item:id,item_code,name,quantity,line_uid'])
            ->get()
            ->map(function (BoqItemMaterialCost $row) use ($project, $boq) {
                $boqQty = Decimal::of((string) ($row->item?->quantity ?? '0'))->toQuantity();
                $qty = Decimal::of((string) $row->quantity)->times($boqQty)->toQuantity();

                return [
                    'type' => 'legacy_material',
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'boq_item_id' => $row->boq_item_id,
                    'item_code' => $row->item?->item_code,
                    'item_name' => $row->item?->name,
                    'unit' => $row->unit,
                    'estimated_qty' => $qty,
                    'rate' => Decimal::of((string) $row->rate)->toRate(),
                    'amount' => Decimal::of($qty)->times((string) $row->rate)->toMoney(),
                    'url' => route('projects.boqs.show', [$project, $boq], absolute: false),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function budgetDrilldown(?ProjectBudget $budget, int $materialId): array
    {
        if ($budget === null) {
            return [];
        }

        return ProjectBudgetLine::query()
            ->where('project_budget_id', $budget->id)
            ->where('material_id', $materialId)
            ->get()
            ->map(fn (ProjectBudgetLine $line) => [
                'budget_id' => $budget->id,
                'budget_version' => $budget->version,
                'line_id' => $line->id,
                'source_type' => $line->source_type?->value,
                'source_label' => $line->source_type === BudgetLineSource::Manual ? 'Manual Budget' : 'Advanced BOQ / Budget',
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
                'description' => $line->description ?? $line->material_name,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerDrilldown(int $projectId, int $materialId, string $asOf): array
    {
        return StockTransaction::query()
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->whereDate('txn_date', '<=', $asOf)
            ->whereIn('txn_type', [
                StockTxnType::IssueOut->value,
                StockTxnType::ReturnIn->value,
                StockTxnType::TransferOut->value,
                StockTxnType::TransferIn->value,
            ])
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'txn_date', 'txn_type', 'qty_in', 'qty_out', 'unit_cost', 'value', 'remarks', 'source_type', 'source_id'])
            ->map(fn (StockTransaction $t) => [
                'id' => $t->id,
                'txn_date' => $t->txn_date?->toDateString(),
                'txn_type' => $t->txn_type?->value ?? $t->txn_type,
                'qty_in' => $t->qty_in,
                'qty_out' => $t->qty_out,
                'unit_cost' => $t->unit_cost,
                'value' => $t->value,
                'remarks' => $t->remarks,
            ])
            ->all();
    }

    private function looksLikeSteel(string $description): bool
    {
        return (bool) preg_match('/\b(steel|tmt|rebar|reinforcement|tor\s*steel)\b/i', mb_strtolower($description));
    }

    private function tryConvert(
        int $companyId,
        int $projectId,
        int $materialId,
        string $fromUnit,
        string $toUnit,
        string $quantity,
    ): ?string {
        if (mb_strtolower(trim($fromUnit)) === mb_strtolower(trim($toUnit))) {
            return Decimal::of($quantity)->toQuantity();
        }
        try {
            return $this->conversions->convert($companyId, $projectId, $materialId, $fromUnit, $toUnit, $quantity)['result'];
        } catch (ValidationException) {
            return null;
        }
    }
}
