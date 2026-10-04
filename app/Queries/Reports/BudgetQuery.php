<?php

namespace App\Queries\Reports;

use App\Enums\Boq\BudgetStatus;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use Illuminate\Support\Facades\DB;

/**
 * Budget = the project's approved budget (one approved version at a time; superseded and draft
 * versions never count), summed per cost head from its lines.
 */
final class BudgetQuery
{
    /**
     * @param  list<int>  $projectIds
     * @return array<int, array<string, Decimal>> project id => cost head => budget
     */
    public function byProjectHead(int $companyId, array $projectIds): array
    {
        $out = [];
        DB::table('project_budget_lines as bl')
            ->join('project_budgets as b', 'b.id', '=', 'bl.project_budget_id')
            ->where('b.company_id', $companyId)
            ->whereIn('b.project_id', $projectIds ?: [0])
            ->where('b.status', BudgetStatus::Approved->value)
            ->whereNull('b.deleted_at')
            ->groupBy('b.project_id', 'bl.cost_head')
            ->selectRaw('b.project_id, bl.cost_head, SUM(bl.amount) as total')
            ->get()
            ->each(function ($row) use (&$out) {
                $out[(int) $row->project_id][$row->cost_head] = Num::dec($row->total);
            });

        return $out;
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, Decimal> cost head => budget across the projects
     */
    public function byHead(int $companyId, array $projectIds): array
    {
        $out = [];
        foreach ($this->byProjectHead($companyId, $projectIds) as $heads) {
            foreach ($heads as $head => $amount) {
                $out[$head] = ($out[$head] ?? Decimal::zero())->plus($amount);
            }
        }

        return $out;
    }
}
