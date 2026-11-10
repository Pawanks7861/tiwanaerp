<?php

namespace App\Reports\Definitions;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdvanceRegisterReport extends ReportDefinition
{
    public function key(): string
    {
        return 'advance-register';
    }

    public function title(): string
    {
        return 'Advance Register';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Labour advances with recovered and balance amounts (sectioned by party type).';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function permissions(): array
    {
        return ['labour.view'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        if (! Schema::hasTable('labour_advances')) {
            return new ReportResult(columns: [], rows: [], cards: [], notes: ['Labour advances table not present.'], pagination: null);
        }

        $base = DB::table('labour_advances as a')
            ->join('projects as p', 'p.id', '=', 'a.project_id')
            ->leftJoin('labours as l', 'l.id', '=', 'a.labour_id')
            ->where('a.company_id', $ctx->companyId())
            ->whereIn('a.project_id', $ctx->projectIds)
            ->selectRaw("a.id, 'labour' as party_type, coalesce(l.name, 'Labour') as party, p.name as project, a.advance_date, a.amount as original_amount, coalesce(a.recovered_amount,0) as recovered, (a.amount - coalesce(a.recovered_amount,0)) as balance, a.advance_number as reference, a.status");

        $count = (clone $base)->count();
        [$rows, $pagination] = $this->rows(DB::query()->fromSub($base, 't'), $ctx, $paginate, fn ($r) => (array) $r);

        return new ReportResult(
            columns: [
                self::col('party_type', 'Type'),
                self::col('party', 'Party'),
                self::col('project', 'Project'),
                self::col('advance_date', 'Date', 'date'),
                self::col('original_amount', 'Original', 'money'),
                self::col('recovered', 'Recovered', 'money'),
                self::col('balance', 'Balance', 'money'),
                self::col('reference', 'Reference'),
                self::col('status', 'Status'),
            ],
            rows: $rows,
            cards: [['key' => 'rows', 'label' => 'Advances', 'value' => $count, 'type' => 'number']],
            notes: ['Vendor/subcontractor advances are not merged here unless stored in compatible ledgers.'],
            pagination: $pagination,
        );
    }
}
