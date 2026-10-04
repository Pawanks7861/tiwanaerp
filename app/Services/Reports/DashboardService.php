<?php

namespace App\Services\Reports;

use App\Enums\CostHead;
use App\Enums\Quality\NcrStatus;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;
use App\Queries\Reports\BudgetQuery;
use App\Queries\Reports\CashFlowQuery;
use App\Queries\Reports\CommittedCostQuery;
use App\Queries\Reports\CostLedgerQuery;
use App\Queries\Reports\PayablesQuery;
use App\Queries\Reports\ProcurementQuery;
use App\Queries\Reports\ProgressQuery;
use App\Queries\Reports\QualityCrmQuery;
use App\Queries\Reports\ReceivablesQuery;
use App\Queries\Reports\StockQuery;
use App\Support\Math\Decimal;
use App\Support\Reports\DashboardCache;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Executive (company) and project dashboards. Every figure comes from the same query classes as
 * the reports (cost ledger, cash documents, progress / stock ledgers). Money is computed only
 * for users with the financial permission — otherwise those keys are never built or sent.
 */
final class DashboardService
{
    public function __construct(
        private readonly DashboardCache $cache,
        private readonly CostLedgerQuery $ledger,
        private readonly BudgetQuery $budgets,
        private readonly CommittedCostQuery $committed,
        private readonly ReceivablesQuery $receivables,
        private readonly PayablesQuery $payables,
        private readonly CashFlowQuery $cash,
        private readonly ProcurementQuery $procurement,
        private readonly ProgressQuery $progress,
        private readonly QualityCrmQuery $quality,
        private readonly StockQuery $stock,
    ) {}

    /**
     * @param  list<int>  $projectIds  projects in scope (already visibility-checked)
     * @param  array{from: string, to: string, as_of: string, fy: string, project_id: int|null}  $period
     * @return array<string, mixed>
     */
    public function executive(Company $company, User $user, array $projectIds, array $period): array
    {
        $financial = $user->can('dashboard.view_financials');
        $cid = (int) $company->id;
        sort($projectIds);

        return $this->cache->remember($cid, 'executive', [
            'projects' => $projectIds, 'financial' => $financial, 'period' => $period,
        ], function () use ($cid, $projectIds, $period, $financial) {
            $projects = DB::table('projects')->where('company_id', $cid)->whereIn('id', $projectIds ?: [0])->whereNull('deleted_at')
                ->orderBy('code')->get(['id', 'code', 'name', 'status', 'contract_value']);
            $progress = $this->progress->summary($cid, $projectIds, $period['as_of']);
            $ncr = $this->quality->ncrStatusCounts($cid, $projectIds);

            $data = [
                'kpis' => [
                    'total_projects' => $projects->count(),
                    'active_projects' => $projects->where('status', 'active')->count(),
                    'overall_progress' => $progress['overall'],
                    'delayed_tasks' => $progress['delayed'],
                    'open_ncrs' => array_sum(array_diff_key($ncr, ['closed' => true])),
                ],
                'charts' => [
                    'progress' => [
                        'categories' => array_values(array_map(fn ($id) => $projects->firstWhere('id', $id)->code ?? (string) $id, array_keys($progress['per_project']))),
                        'series' => [['name' => 'Progress %', 'data' => array_values($progress['per_project'])]],
                    ],
                    'ncr' => [
                        'categories' => array_map(fn (NcrStatus $s) => $s->label(), NcrStatus::cases()),
                        'series' => array_map(fn (NcrStatus $s) => $ncr[$s->value] ?? 0, NcrStatus::cases()),
                    ],
                ],
            ];

            if (! $financial) {
                return $data;
            }

            $byHead = $this->ledger->byHead($cid, $projectIds, $period['from'], $period['to']);
            $actualToDate = $this->ledger->byProjectHead($cid, $projectIds, null, $period['as_of']);
            $budget = $this->budgets->byProjectHead($cid, $projectIds);
            $receivable = $this->receivables->totals($this->receivables->base($cid, $projectIds, $period['as_of']));
            $payable = $this->payables->outstandingByKind($cid, $projectIds, $period['as_of']);
            $monthly = $this->cash->monthly($cid, $projectIds, $period['from'], $period['to']);
            $budgetTotal = Decimal::zero();
            foreach ($budget as $heads) {
                $budgetTotal = $budgetTotal->plus(Decimal::sum(array_values($heads)));
            }

            $data['kpis'] += [
                'project_value' => Decimal::sum($projects->whereNotIn('status', ['cancelled'])->pluck('contract_value')->map(fn ($v) => (string) ($v ?? '0'))->all())->toMoney(),
                'budget' => $budgetTotal->toMoney(),
                'actual_cost' => Decimal::sum(array_values($byHead))->toMoney(),
                'outstanding_receivables' => $receivable['outstanding']->toMoney(),
                'vendor_payables' => $payable['vendor']->toMoney(),
                'purchase_value' => $this->procurement->purchaseValue($cid, $projectIds, $period['from'], $period['to']),
                'material_cost' => $byHead['material']->toMoney(),
                'labour_cost' => $byHead['labour']->toMoney(),
                'equipment_cost' => $byHead['equipment']->toMoney(),
                'subcontract_cost' => $byHead['subcontract']->toMoney(),
            ];

            $bva = [];
            foreach ($projects as $p) {
                $b = Decimal::sum(array_values($budget[$p->id] ?? []));
                $a = Decimal::sum(array_values($actualToDate[$p->id] ?? []));
                if (! $b->isZero() || ! $a->isZero()) {
                    $bva[] = ['code' => $p->code, 'budget' => $b->toMoney(), 'actual' => $a->toMoney()];
                }
            }
            $bva = array_slice($bva, 0, 12);

            $data['charts'] += [
                'budget_vs_actual' => [
                    'categories' => array_column($bva, 'code'),
                    'series' => [['name' => 'Budget', 'data' => array_column($bva, 'budget')], ['name' => 'Actual to date', 'data' => array_column($bva, 'actual')]],
                ],
                'cost_by_head' => [
                    'categories' => array_map(fn (CostHead $h) => $h->label(), CostHead::cases()),
                    'series' => array_map(fn (CostHead $h) => $byHead[$h->value]->toMoney(), CostHead::cases()),
                ],
                'cash_flow' => [
                    'categories' => array_map(fn ($m) => date('M Y', strtotime($m.'-01')), array_keys($monthly)),
                    'series' => [
                        ['name' => 'Inflow', 'data' => array_values(array_map(fn ($m) => $m['inflow']->toMoney(), $monthly))],
                        ['name' => 'Outflow', 'data' => array_values(array_map(fn ($m) => $m['outflow']->toMoney(), $monthly))],
                    ],
                ],
                'receivables_payables' => [
                    'categories' => ['Client receivables', 'Vendor payables', 'Subcontract payables', 'Labour payables'],
                    'series' => [['name' => 'Outstanding', 'data' => [
                        $receivable['outstanding']->toMoney(), $payable['vendor']->toMoney(), $payable['subcontract']->toMoney(), $payable['labour']->toMoney(),
                    ]]],
                ],
            ];

            return $data;
        });
    }

    /**
     * Project overview: operational and financial sections kept apart; financial only with
     * dashboard.view_financials, stock value only with inventory.view_valuation too.
     *
     * @return array<string, mixed>
     */
    public function project(Project $project, User $user, string $today): array
    {
        $financial = $user->can('dashboard.view_financials');
        $valuation = $financial && $user->can('inventory.view_valuation');
        $cid = (int) $project->company_id;
        $pid = (int) $project->id;

        return $this->cache->remember($cid, 'project', ['project' => $pid, 'financial' => $financial, 'valuation' => $valuation, 'today' => $today],
            function () use ($cid, $pid, $today, $financial, $valuation) {
                $progress = $this->progress->summary($cid, [$pid], $today);
                $ncr = $this->quality->ncrStatusCounts($cid, [$pid]);
                $inspections = DB::table('quality_inspections')->where('company_id', $cid)->where('project_id', $pid)->whereNull('deleted_at')
                    ->where('status', '<>', 'completed')->count();
                $warehouses = $this->stock->warehouseIds($cid, [$pid], false);
                $lowStock = DB::query()->fromSub($this->stock->summary($cid, $warehouses, $today, ['status' => 'low']), 'l')->count();

                $data = ['operational' => [
                    'progress' => $progress['overall'],
                    'tasks' => $progress['tasks'],
                    'completed_tasks' => $progress['completed'],
                    'delayed_tasks' => $progress['delayed'],
                    'in_progress_tasks' => $progress['in_progress'],
                    'open_ncrs' => array_sum(array_diff_key($ncr, ['closed' => true])),
                    'pending_inspections' => $inspections,
                    'low_stock' => $lowStock,
                ]];
                if (! $financial) {
                    return $data;
                }

                $budget = Decimal::sum(array_values($this->budgets->byProjectHead($cid, [$pid])[$pid] ?? []));
                $byHead = $this->ledger->byHead($cid, [$pid], null, $today);
                $actual = Decimal::sum(array_values($byHead));
                $committed = $this->committed->byProject($cid, [$pid])[$pid]['total'] ?? Decimal::zero();
                $billing = $this->receivables->byProject($cid, [$pid], $today)[$pid] ?? null;

                $data['financial'] = [
                    'budget' => $budget->toMoney(),
                    'actual' => $actual->toMoney(),
                    'committed' => $committed->toMoney(),
                    'remaining' => $budget->minus($actual)->minus($committed)->toMoney(),
                    'utilization' => Num::percent($actual, $budget),
                    'billed' => ($billing['billed'] ?? Decimal::zero())->toMoney(),
                    'received' => ($billing['received'] ?? Decimal::zero())->toMoney(),
                    'outstanding' => ($billing['outstanding'] ?? Decimal::zero())->toMoney(),
                    'procurement_value' => $this->procurement->purchaseValue($cid, [$pid], null, null),
                    'cost_by_head' => [
                        'categories' => array_map(fn (CostHead $h) => $h->label(), CostHead::cases()),
                        'series' => array_map(fn (CostHead $h) => $byHead[$h->value]->toMoney(), CostHead::cases()),
                    ],
                ];
                if ($valuation) {
                    $data['financial']['stock_value'] = Num::money(DB::query()->fromSub($this->stock->summary($cid, $warehouses, $today), 's')->sum('value'));
                }

                return $data;
            });
    }
}
