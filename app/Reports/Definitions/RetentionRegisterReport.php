<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RetentionRegisterReport extends ReportDefinition
{
    public function key(): string
    {
        return 'retention-register';
    }

    public function title(): string
    {
        return 'Retention Register';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Client and subcontract retention withheld, released and balance where columns exist.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function permissions(): array
    {
        return ['billing.view'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $unions = [];

        if (Schema::hasTable('client_invoices') && Schema::hasColumn('client_invoices', 'retention_amount')) {
            $unions[] = DB::table('client_invoices as ci')
                ->join('projects as p', 'p.id', '=', 'ci.project_id')
                ->leftJoin('clients as c', 'c.id', '=', 'ci.client_id')
                ->where('ci.company_id', $ctx->companyId())
                ->whereIn('ci.project_id', $ctx->projectIds)
                ->selectRaw("'client' as party_type, coalesce(c.name,'Client') as party, p.name as project, ci.invoice_number as bill, ci.invoice_date as bill_date, coalesce(ci.retention_amount,0) as withheld, coalesce(ci.retention_released,0) as released, (coalesce(ci.retention_amount,0)-coalesce(ci.retention_released,0)) as balance, ci.status, null as release_date");
        }

        if (Schema::hasTable('subcontractor_bills') && Schema::hasColumn('subcontractor_bills', 'retention_amount')) {
            $unions[] = DB::table('subcontractor_bills as sb')
                ->join('projects as p', 'p.id', '=', 'sb.project_id')
                ->leftJoin('subcontractors as s', 's.id', '=', 'sb.subcontractor_id')
                ->where('sb.company_id', $ctx->companyId())
                ->whereIn('sb.project_id', $ctx->projectIds)
                ->selectRaw("'subcontractor' as party_type, coalesce(s.name,'Subcontractor') as party, p.name as project, sb.bill_number as bill, sb.bill_date as bill_date, coalesce(sb.retention_amount,0) as withheld, 0 as released, coalesce(sb.retention_amount,0) as balance, sb.status, null as release_date");
        }

        if ($unions === []) {
            return new ReportResult(columns: [], rows: [], cards: [], notes: ['No retention columns found.'], pagination: null);
        }

        $base = array_shift($unions);
        foreach ($unions as $q) {
            $base->unionAll($q);
        }

        $count = DB::query()->fromSub($base, 't')->count();
        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('party_type', 'Type'),
                self::col('party', 'Party'),
                self::col('project', 'Project'),
                self::col('bill', 'Bill'),
                self::col('bill_date', 'Bill date', 'date'),
                self::col('withheld', 'Withheld', 'money'),
                self::col('released', 'Released', 'money'),
                self::col('balance', 'Balance', 'money'),
                self::col('status', 'Status'),
            ],
            rows: $rows,
            cards: [['key' => 'rows', 'label' => 'Retention rows', 'value' => $count, 'type' => 'number']],
            notes: ['Release amounts come from retention release documents when linked columns exist.'],
            pagination: $pagination,
        );
    }
}
