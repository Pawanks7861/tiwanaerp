<?php

namespace App\Queries\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quality (inspections / NCRs) and CRM funnel aggregates. Timestamp columns (created_at,
 * completed_at, closed_at) are stored in UTC, so period bounds are converted from the company's
 * time zone first.
 */
final class QualityCrmQuery
{
    /**
     * @return array{0: string, 1: string} UTC [start, end] for a local date range
     */
    public static function utcRange(string $from, string $to, string $timezone): array
    {
        return [
            CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->toDateTimeString(),
            CarbonImmutable::parse($to, $timezone)->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    /**
     * Per project: inspections requested / completed / results in the period; NCRs raised and
     * closed in the period; open, overdue and severity of NCRs open at the period end.
     *
     * @param  list<int>  $projectIds
     */
    public function qualityByProject(int $companyId, array $projectIds, string $from, string $to, string $timezone, string $today): Builder
    {
        [$start, $end] = self::utcRange($from, $to, $timezone);
        $ids = $projectIds ?: [0];

        $inspections = DB::table('quality_inspections')
            ->where('company_id', $companyId)->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->selectRaw("project_id,
                SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as requested,
                SUM(CASE WHEN status = 'completed' AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'completed' AND result = 'passed' AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN status = 'completed' AND result = 'failed' AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'completed' AND result = 'conditional' AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as conditional,
                SUM(CASE WHEN status <> 'completed' THEN 1 ELSE 0 END) as pending,
                0 as ncr_raised, 0 as ncr_closed, 0 as ncr_open, 0 as ncr_overdue, 0 as ncr_critical, 0 as ncr_major, 0 as ncr_minor",
                [$start, $end, $start, $end, $start, $end, $start, $end, $start, $end])
            ->groupBy('project_id');

        $ncrs = DB::table('ncrs')
            ->where('company_id', $companyId)->whereIn('project_id', $ids)->whereNull('deleted_at')
            ->selectRaw("project_id, 0 as requested, 0 as completed, 0 as passed, 0 as failed, 0 as conditional, 0 as pending,
                SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as ncr_raised,
                SUM(CASE WHEN status = 'closed' AND closed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as ncr_closed,
                SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) as ncr_open,
                SUM(CASE WHEN status <> 'closed' AND target_date < ? THEN 1 ELSE 0 END) as ncr_overdue,
                SUM(CASE WHEN status <> 'closed' AND severity = 'critical' THEN 1 ELSE 0 END) as ncr_critical,
                SUM(CASE WHEN status <> 'closed' AND severity = 'major' THEN 1 ELSE 0 END) as ncr_major,
                SUM(CASE WHEN status <> 'closed' AND severity = 'minor' THEN 1 ELSE 0 END) as ncr_minor",
                [$start, $end, $start, $end, $today])
            ->groupBy('project_id');

        return DB::query()->fromSub($inspections->unionAll($ncrs), 'q')
            ->join('projects as pr', 'pr.id', '=', 'q.project_id')
            ->groupBy('q.project_id', 'pr.code', 'pr.name')
            ->selectRaw('q.project_id, pr.code as project_code, pr.name as project,
                SUM(q.requested) as requested, SUM(q.completed) as completed, SUM(q.passed) as passed, SUM(q.failed) as failed,
                SUM(q.conditional) as conditional, SUM(q.pending) as pending, SUM(q.ncr_raised) as ncr_raised, SUM(q.ncr_closed) as ncr_closed,
                SUM(q.ncr_open) as ncr_open, SUM(q.ncr_overdue) as ncr_overdue, SUM(q.ncr_critical) as ncr_critical,
                SUM(q.ncr_major) as ncr_major, SUM(q.ncr_minor) as ncr_minor');
    }

    /**
     * NCR counts by status (current), for dashboards.
     *
     * @param  list<int>  $projectIds
     * @return array<string, int>
     */
    public function ncrStatusCounts(int $companyId, array $projectIds): array
    {
        return DB::table('ncrs')->where('company_id', $companyId)->whereIn('project_id', $projectIds ?: [0])->whereNull('deleted_at')
            ->groupBy('status')->selectRaw('status, COUNT(*) as cnt')->pluck('cnt', 'status')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Lead funnel for leads created in the period, grouped by source / assignee / project type.
     * Quotation value counts accepted quotations (latest revisions only; superseded ones are
     * 'revised') of those leads; conversions count quotations converted into projects.
     */
    public function leadFunnel(int $companyId, string $from, string $to, string $timezone, string $group, ?int $assigneeId = null): Builder
    {
        [$start, $end] = self::utcRange($from, $to, $timezone);
        $groupExpr = match ($group) {
            'assignee' => "COALESCE(u.name, 'Unassigned')",
            'project_type' => "COALESCE(l.project_type, 'Not set')",
            default => "COALESCE(l.source, 'Not set')",
        };

        $quotes = DB::table('quotations')
            ->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('lead_id')->where('status', '<>', 'revised')
            ->groupBy('lead_id')
            ->selectRaw("lead_id, COUNT(*) as quotes,
                SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_value,
                SUM(CASE WHEN converted_project_id IS NOT NULL THEN 1 ELSE 0 END) as converted");

        return DB::table('leads as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.assigned_to')
            ->leftJoinSub($quotes, 'q', 'q.lead_id', '=', 'l.id')
            ->where('l.company_id', $companyId)
            ->whereNull('l.deleted_at')
            ->whereBetween('l.created_at', [$start, $end])
            ->when($assigneeId, fn ($q, $id) => $q->where('l.assigned_to', $id))
            ->groupByRaw($groupExpr)
            ->selectRaw("{$groupExpr} as label, COUNT(*) as leads,
                SUM(CASE WHEN l.status = 'new' THEN 1 ELSE 0 END) as new_leads,
                SUM(CASE WHEN l.status = 'contacted' THEN 1 ELSE 0 END) as contacted,
                SUM(CASE WHEN l.status = 'qualified' THEN 1 ELSE 0 END) as qualified,
                SUM(CASE WHEN l.status = 'quoted' THEN 1 ELSE 0 END) as quoted,
                SUM(CASE WHEN l.status = 'won' THEN 1 ELSE 0 END) as won,
                SUM(CASE WHEN l.status = 'lost' THEN 1 ELSE 0 END) as lost,
                SUM(COALESCE(l.estimated_value, 0)) as pipeline_value,
                SUM(COALESCE(q.quotes, 0)) as quotes,
                SUM(COALESCE(q.accepted_value, 0)) as accepted_value,
                SUM(COALESCE(q.converted, 0)) as converted");
    }
}
