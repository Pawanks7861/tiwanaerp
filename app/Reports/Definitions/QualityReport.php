<?php

namespace App\Reports\Definitions;

use App\Enums\Quality\NcrStatus;
use App\Queries\Reports\QualityCrmQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;

final class QualityReport extends ReportDefinition
{
    private const COUNTS = ['requested', 'completed', 'passed', 'failed', 'conditional', 'pending', 'ncr_raised', 'ncr_closed', 'ncr_open', 'ncr_overdue',
        'ncr_critical', 'ncr_major', 'ncr_minor'];

    public function __construct(private readonly QualityCrmQuery $quality) {}

    public function key(): string
    {
        return 'quality-summary';
    }

    public function title(): string
    {
        return 'Quality & NCR Summary';
    }

    public function category(): string
    {
        return 'quality';
    }

    public function description(): string
    {
        return 'Inspections requested and completed with results, and NCRs raised, closed, open and overdue by severity.';
    }

    public function permissions(): array
    {
        return ['quality.view'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $cid = $ctx->companyId();
        $timezone = $ctx->company->timezone ?: config('app.timezone');
        $sum = array_fill_keys(self::COUNTS, 0);
        $rows = [];
        foreach ($this->quality->qualityByProject($cid, $ctx->projectIds, (string) $ctx->from, $ctx->to, $timezone, $ctx->today)->orderBy('pr.code')->get() as $r) {
            $row = ['project' => "{$r->project_code} — {$r->project}", 'url' => route('projects.ncrs.index', [$r->project_id])];
            foreach (self::COUNTS as $key) {
                $row[$key] = (int) $r->{$key};
                $sum[$key] += (int) $r->{$key};
            }
            $row['pass_rate'] = Num::percent($row['passed'], $row['completed']);
            $rows[] = $row;
        }

        $statuses = $this->quality->ncrStatusCounts($cid, $ctx->projectIds);

        return new ReportResult(
            columns: [
                self::col('project', 'Project', 'text', ['link' => true]),
                self::col('requested', 'Inspections requested', 'number'),
                self::col('completed', 'Completed', 'number'),
                self::col('passed', 'Passed', 'number', ['mobile' => false]),
                self::col('failed', 'Failed', 'number', ['mobile' => false]),
                self::col('conditional', 'Conditional', 'number', ['mobile' => false]),
                self::col('pass_rate', 'Pass rate', 'percent'),
                self::col('pending', 'Pending now', 'number', ['mobile' => false]),
                self::col('ncr_raised', 'NCRs raised', 'number'),
                self::col('ncr_closed', 'NCRs closed', 'number', ['mobile' => false]),
                self::col('ncr_open', 'Open NCRs', 'number'),
                self::col('ncr_overdue', 'Overdue', 'number'),
                self::col('ncr_critical', 'Critical', 'number', ['mobile' => false]),
                self::col('ncr_major', 'Major', 'number', ['mobile' => false]),
                self::col('ncr_minor', 'Minor', 'number', ['mobile' => false]),
            ],
            rows: $rows,
            totals: ['project' => 'Total'] + $sum + ['pass_rate' => Num::percent($sum['passed'], $sum['completed'])],
            cards: [
                ['key' => 'inspections', 'label' => 'Inspections completed', 'value' => $sum['completed'], 'type' => 'number'],
                ['key' => 'pass_rate', 'label' => 'Pass rate', 'value' => Num::percent($sum['passed'], $sum['completed']), 'type' => 'percent'],
                ['key' => 'open', 'label' => 'Open NCRs', 'value' => $sum['ncr_open'], 'type' => 'number', 'tone' => $sum['ncr_open'] > 0 ? 'warning' : 'success'],
                ['key' => 'overdue', 'label' => 'Overdue NCRs', 'value' => $sum['ncr_overdue'], 'type' => 'number', 'tone' => $sum['ncr_overdue'] > 0 ? 'danger' : 'success'],
            ],
            charts: [[
                'type' => 'donut', 'title' => 'NCRs by status (current)',
                'categories' => array_map(fn (NcrStatus $s) => $s->label(), NcrStatus::cases()),
                'series' => array_map(fn (NcrStatus $s) => $statuses[$s->value] ?? 0, NcrStatus::cases()),
            ]],
            notes: ['Requested / raised count by creation date, completed / closed by completion date, in the company time zone. Open, overdue, pending and severity counts are current.'],
        );
    }
}
