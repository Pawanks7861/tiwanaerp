<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\QualityCrmQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Math\Decimal;
use App\Support\Reports\Num;

final class CrmFunnelReport extends ReportDefinition
{
    private const COUNTS = ['leads', 'new_leads', 'contacted', 'qualified', 'quoted', 'won', 'lost', 'quotes', 'converted'];

    public function __construct(private readonly QualityCrmQuery $crm) {}

    public function key(): string
    {
        return 'crm-funnel';
    }

    public function title(): string
    {
        return 'CRM Lead Funnel';
    }

    public function category(): string
    {
        return 'crm';
    }

    public function description(): string
    {
        return 'Leads created in the period by stage, win rate, pipeline value, accepted quotations and conversions to projects.';
    }

    public function permissions(): array
    {
        return ['crm.leads.view'];
    }

    public function scopes(): array
    {
        return ['global'];
    }

    public function filters(): array
    {
        return ['view', 'assignee'];
    }

    public function viewOptions(): array
    {
        return ['source' => 'By source', 'assignee' => 'By assignee', 'project_type' => 'By project type'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $group = $ctx->filter('view', 'source');
        $timezone = $ctx->company->timezone ?: config('app.timezone');
        $sum = array_fill_keys(self::COUNTS, 0);
        $pipeline = Decimal::zero();
        $accepted = Decimal::zero();
        $rows = [];
        $assignee = $ctx->filter('assignee_id') ? (int) $ctx->filter('assignee_id') : null;
        foreach ($this->crm->leadFunnel($ctx->companyId(), (string) $ctx->from, $ctx->to, $timezone, $group, $assignee)->orderBy('label')->get() as $r) {
            $row = ['label' => ucwords(str_replace('_', ' ', (string) $r->label))];
            foreach (self::COUNTS as $key) {
                $row[$key] = (int) $r->{$key};
                $sum[$key] += (int) $r->{$key};
            }
            $row['win_rate'] = Num::percent($row['won'], $row['won'] + $row['lost']);
            $row['pipeline_value'] = Num::money($r->pipeline_value);
            $row['accepted_value'] = Num::money($r->accepted_value);
            $pipeline = $pipeline->plus(Num::dec($r->pipeline_value));
            $accepted = $accepted->plus(Num::dec($r->accepted_value));
            $rows[] = $row;
        }

        $groupLabel = $this->viewOptions()[$group] ?? 'Group';

        return new ReportResult(
            columns: [
                self::col('label', ucfirst(str_replace('By ', '', $groupLabel))),
                self::col('leads', 'Leads', 'number'),
                self::col('new_leads', 'New', 'number', ['mobile' => false]),
                self::col('contacted', 'Contacted', 'number', ['mobile' => false]),
                self::col('qualified', 'Qualified', 'number', ['mobile' => false]),
                self::col('quoted', 'Quoted', 'number', ['mobile' => false]),
                self::col('won', 'Won', 'number'),
                self::col('lost', 'Lost', 'number', ['mobile' => false]),
                self::col('win_rate', 'Win rate', 'percent'),
                self::money('pipeline_value', 'Estimated value', ['mobile' => false]),
                self::col('quotes', 'Quotations', 'number', ['mobile' => false]),
                self::money('accepted_value', 'Accepted value'),
                self::col('converted', 'Converted to projects', 'number'),
            ],
            rows: $rows,
            totals: ['label' => 'Total'] + $sum + ['win_rate' => Num::percent($sum['won'], $sum['won'] + $sum['lost']),
                'pipeline_value' => $pipeline->toMoney(), 'accepted_value' => $accepted->toMoney()],
            cards: [
                ['key' => 'leads', 'label' => 'Leads', 'value' => $sum['leads'], 'type' => 'number'],
                ['key' => 'won', 'label' => 'Won', 'value' => $sum['won'], 'type' => 'number', 'tone' => 'success'],
                ['key' => 'win_rate', 'label' => 'Win rate', 'value' => Num::percent($sum['won'], $sum['won'] + $sum['lost']), 'type' => 'percent'],
                ['key' => 'accepted', 'label' => 'Accepted quotations', 'value' => $accepted->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
            ],
            charts: [[
                'type' => 'bar', 'horizontal' => true, 'title' => 'Funnel',
                'categories' => ['New', 'Contacted', 'Qualified', 'Quoted', 'Won', 'Lost'],
                'series' => [['name' => 'Leads', 'data' => [$sum['new_leads'], $sum['contacted'], $sum['qualified'], $sum['quoted'], $sum['won'], $sum['lost']]]],
            ]],
            notes: ['Leads created in the period (company time zone), at their current stage. Win rate = won ÷ (won + lost). Accepted value counts the latest quotation revisions only.'],
        );
    }
}
