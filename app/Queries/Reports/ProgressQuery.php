<?php

namespace App\Queries\Reports;

use App\Support\Math\Decimal;
use App\Support\Reports\Num;
use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Task progress from progress_entries (signed quantities; reversals net out), as of a date.
 * Percent follows TaskProgressService: completed status = 100; with a planned quantity,
 * completed ÷ planned × 100 capped at 100 (negative nets count as 0); without one, 0.
 * Overall project progress = average percent of leaf tasks (the progress board's rule).
 */
final class ProgressQuery
{
    /**
     * @param  list<int>  $projectIds
     * @param  array{status?: string|null, assignee_id?: int|null, search?: string|null, leaf?: bool, delayed?: bool}  $filters
     */
    public function tasks(int $companyId, array $projectIds, string $asOf, array $filters = []): Builder
    {
        $ids = $projectIds ?: [0];
        $done = DB::table('progress_entries')
            ->where('company_id', $companyId)->whereIn('project_id', $ids)->where('entry_date', '<=', Sql::eod($asOf))
            ->whereNotNull('task_id')->groupBy('task_id')->selectRaw('task_id, SUM(quantity) as done');
        $search = trim((string) ($filters['search'] ?? ''));
        $leaf = 'NOT EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = t.id AND c.deleted_at IS NULL)';

        return DB::table('project_tasks as t')
            ->join('projects as pr', 'pr.id', '=', 't.project_id')
            ->leftJoinSub($done, 'd', 'd.task_id', '=', 't.id')
            ->leftJoin('units as un', 'un.id', '=', 't.unit_id')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->leftJoin('boq_items as bi', 'bi.id', '=', 't.boq_item_id')
            ->where('t.company_id', $companyId)
            ->whereIn('t.project_id', $ids)
            ->whereNull('t.deleted_at')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('t.status', $s))
            ->when($filters['assignee_id'] ?? null, fn ($q, $id) => $q->where('t.assigned_to', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('t.name', 'like', "%{$search}%")->orWhere('t.wbs_code', 'like', "%{$search}%")))
            ->when($filters['leaf'] ?? false, fn ($q) => $q->whereRaw($leaf))
            ->when($filters['delayed'] ?? false, fn ($q) => $q->whereNotIn('t.status', ['completed', 'on_hold'])
                ->where(fn ($w) => $w->where('t.status', 'delayed')->orWhere('t.planned_finish', '<', $asOf)))
            ->select('t.id', 't.project_id', 'pr.code as project_code', 't.wbs_code', 't.name', 't.status', 't.planned_start', 't.planned_finish',
                't.actual_start', 't.actual_finish', 't.planned_qty', 't.completed_qty as cached_qty', 'un.symbol as unit', 'u.name as assignee',
                'bi.item_code as boq_code', 't.assigned_to')
            ->selectRaw('COALESCE(d.done, 0) as done')
            ->selectRaw("CASE WHEN {$leaf} THEN 1 ELSE 0 END as is_leaf")
            ->selectRaw(Sql::days('t.planned_finish', '?').' as overdue_days', [$asOf]);
    }

    /** Percent for one task row (exact, 2 dp). */
    public static function percent(object $row): string
    {
        if ($row->status === 'completed') {
            return '100.00';
        }
        $planned = Num::dec($row->planned_qty);
        if (! $planned->isPositive()) {
            return '0.00';
        }
        $done = Num::dec($row->done);
        if ($done->isNegative()) {
            return '0.00';
        }
        $percent = $done->dividedBy($planned)->times(100);

        return ($percent->greaterThan(100) ? Decimal::of(100) : $percent)->round(2)->toString();
    }

    /**
     * Overall % (average over leaf tasks) and status counts, per project and in total.
     *
     * @param  list<int>  $projectIds
     * @return array{overall: string|null, tasks: int, leaf_tasks: int, completed: int, delayed: int, in_progress: int, not_started: int, on_hold: int, per_project: array<int, string|null>}
     */
    public function summary(int $companyId, array $projectIds, string $asOf): array
    {
        $percent = "CASE WHEN status = 'completed' THEN 100
            WHEN planned_qty > 0 AND done > 0 THEN (CASE WHEN done * 100.0 / planned_qty > 100 THEN 100 ELSE done * 100.0 / planned_qty END)
            ELSE 0 END";
        $leafRows = DB::query()->fromSub($this->tasks($companyId, $projectIds, $asOf, ['leaf' => true]), 'x');

        $perProject = (clone $leafRows)->groupBy('project_id')
            ->selectRaw("project_id, SUM({$percent}) as total, COUNT(*) as cnt")->get()
            ->mapWithKeys(fn ($r) => [(int) $r->project_id => $r->cnt > 0 ? Num::dec($r->total)->dividedBy((int) $r->cnt)->round(2)->toString() : null])
            ->all();
        $overall = (clone $leafRows)->selectRaw("SUM({$percent}) as total, COUNT(*) as cnt")->first();

        $counts = DB::table('project_tasks')->where('company_id', $companyId)->whereIn('project_id', $projectIds ?: [0])->whereNull('deleted_at')
            ->groupBy('status')->selectRaw('status, COUNT(*) as cnt')->pluck('cnt', 'status');

        return [
            'overall' => ($overall->cnt ?? 0) > 0 ? Num::dec($overall->total)->dividedBy((int) $overall->cnt)->round(2)->toString() : null,
            'tasks' => (int) $counts->sum(),
            'leaf_tasks' => (int) ($overall->cnt ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'delayed' => (int) ($counts['delayed'] ?? 0),
            'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'not_started' => (int) ($counts['not_started'] ?? 0),
            'on_hold' => (int) ($counts['on_hold'] ?? 0),
            'per_project' => $perProject,
        ];
    }
}
