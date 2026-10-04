<?php

namespace App\Queries\Reports;

use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Material issued to projects (approved issues less approved site-to-store returns) against the
 * usage engineers recorded in approved site diaries. Diary usage is informational only — it never
 * moves stock or cost; the variance just highlights waste or under-reporting.
 */
final class MaterialConsumptionQuery
{
    /**
     * @param  list<int>  $projectIds
     * @param  array{material_id?: int|null, search?: string|null}  $filters
     */
    public function base(int $companyId, array $projectIds, string $from, string $to, array $filters = []): Builder
    {
        $ids = $projectIds ?: [0];
        $toEod = Sql::eod($to);

        $issued = DB::table('material_issue_items as ii')
            ->join('material_issues as mi', 'mi.id', '=', 'ii.material_issue_id')
            ->where('mi.company_id', $companyId)->whereIn('mi.project_id', $ids)->where('mi.status', 'approved')->whereNull('mi.deleted_at')
            ->where('mi.issue_date', '>=', $from)->where('mi.issue_date', '<=', $toEod)
            ->selectRaw('mi.project_id, ii.material_id, ii.quantity as issued, ii.amount as issued_value, 0 as returned, 0 as returned_value, 0 as diary');

        $returned = DB::table('material_return_items as ri')
            ->join('material_returns as mr', 'mr.id', '=', 'ri.material_return_id')
            ->where('mr.company_id', $companyId)->whereIn('mr.project_id', $ids)->where('mr.status', 'approved')
            ->where('mr.return_type', 'site_to_store')->whereNull('mr.deleted_at')
            ->where('mr.return_date', '>=', $from)->where('mr.return_date', '<=', $toEod)
            ->selectRaw('mr.project_id, ri.material_id, 0 as issued, 0 as issued_value, ri.quantity as returned, ri.value as returned_value, 0 as diary');

        $diary = DB::table('site_diary_materials as dm')
            ->join('site_diaries as sd', 'sd.id', '=', 'dm.site_diary_id')
            ->where('sd.company_id', $companyId)->whereIn('sd.project_id', $ids)->where('sd.status', 'approved')->whereNull('sd.deleted_at')
            ->where('sd.diary_date', '>=', $from)->where('sd.diary_date', '<=', $toEod)
            ->selectRaw('sd.project_id, dm.material_id, 0 as issued, 0 as issued_value, 0 as returned, 0 as returned_value, dm.quantity as diary');

        $search = trim((string) ($filters['search'] ?? ''));

        return DB::query()->fromSub($issued->unionAll($returned)->unionAll($diary), 'm')
            ->join('materials as mt', 'mt.id', '=', 'm.material_id')
            ->join('projects as pr', 'pr.id', '=', 'm.project_id')
            ->leftJoin('units as un', 'un.id', '=', 'mt.unit_id')
            ->when($filters['material_id'] ?? null, fn ($q, $id) => $q->where('m.material_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('mt.name', 'like', "%{$search}%")->orWhere('mt.code', 'like', "%{$search}%")))
            ->groupBy('m.project_id', 'pr.code', 'm.material_id', 'mt.code', 'mt.name', 'un.symbol')
            ->selectRaw('m.project_id, pr.code as project_code, m.material_id, mt.code as material_code, mt.name as material, un.symbol as unit,
                SUM(m.issued) as issued, SUM(m.returned) as returned, SUM(m.issued) - SUM(m.returned) as net_issued,
                SUM(m.issued_value) - SUM(m.returned_value) as net_value, SUM(m.diary) as diary,
                SUM(m.issued) - SUM(m.returned) - SUM(m.diary) as variance');
    }
}
