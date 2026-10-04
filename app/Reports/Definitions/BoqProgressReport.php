<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\BoqProgressQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;

final class BoqProgressReport extends ReportDefinition
{
    public function __construct(private readonly BoqProgressQuery $boq) {}

    public function key(): string
    {
        return 'boq-planned-actual';
    }

    public function title(): string
    {
        return 'BOQ Planned vs Actual';
    }

    public function category(): string
    {
        return 'project';
    }

    public function description(): string
    {
        return 'Each line of the current approved BOQ: quantity executed from the progress ledger and actual cost from the cost ledger.';
    }

    public function permissions(): array
    {
        return ['boq.view'];
    }

    public function scopes(): array
    {
        return ['project'];
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function filters(): array
    {
        return ['search'];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        $boq = $ctx->project ? $this->boq->currentBoq($ctx->companyId(), (int) $ctx->project->id) : null;

        return $boq ? $this->boq->lines($ctx->companyId(), (int) $ctx->project->id, (int) $boq->id, $ctx->asOf())->count() : 0;
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $columns = [
            self::col('item', 'BOQ item'),
            self::col('unit', 'Unit', 'text', ['mobile' => false]),
            self::col('quantity', 'BOQ qty', 'qty'),
            self::col('executed', 'Executed', 'qty'),
            self::col('balance', 'Balance', 'qty', ['mobile' => false]),
            self::col('percent', 'Executed %', 'percent'),
            self::money('planned_cost', 'Planned cost (BOQ)', ['mobile' => false]),
            self::money('actual', 'Actual cost'),
            self::money('variance', 'Cost variance'),
            self::money('client_amount', 'Client value', ['mobile' => false]),
        ];
        $cid = $ctx->companyId();
        $boq = $ctx->project ? $this->boq->currentBoq($cid, (int) $ctx->project->id) : null;
        if ($boq === null) {
            return new ReportResult(columns: $columns, notes: ['This project has no approved BOQ yet.']);
        }

        $pid = (int) $ctx->project->id;
        $query = $this->boq->lines($cid, $pid, (int) $boq->id, $ctx->asOf(), $ctx->filter('search'))->orderBy('bi.sort_order')->orderBy('bi.id');
        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, function ($r) {
            $qty = Num::dec($r->quantity);
            $done = Num::dec($r->executed);
            $planned = Num::dec($r->cost_amount);
            $actual = Num::dec($r->actual);

            return [
                'item' => trim("{$r->item_code} {$r->name}"),
                'unit' => $r->unit,
                'quantity' => $qty->toQuantity(),
                'executed' => $done->toQuantity(),
                'balance' => $qty->minus($done)->toQuantity(),
                'percent' => Num::percent($done, $qty),
                'planned_cost' => $planned->toMoney(),
                'actual' => $actual->toMoney(),
                'variance' => $planned->minus($actual)->toMoney(),
                'client_amount' => Num::money($r->client_amount),
                'flag' => $done->greaterThan($qty) ? 'Executed beyond BOQ quantity' : null,
            ];
        });
        $totals = $this->boq->totals($cid, $pid, (int) $boq->id, $ctx->asOf());

        return new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: ['item' => 'Total ('.$totals['lines'].' lines)', 'planned_cost' => $totals['planned_cost'], 'actual' => $totals['actual'],
                'variance' => Num::dec($totals['planned_cost'])->minus(Num::dec($totals['actual']))->toMoney(), 'client_amount' => $totals['client_amount']],
            cards: [
                ['key' => 'boq', 'label' => 'BOQ', 'value' => "{$boq->boq_number} v{$boq->version}", 'type' => 'text'],
                ['key' => 'planned', 'label' => 'Planned cost', 'value' => $totals['planned_cost'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'actual', 'label' => 'Actual on BOQ lines', 'value' => $totals['actual'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'unlinked', 'label' => 'Cost not linked to a BOQ line', 'value' => $totals['unlinked_cost'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: ['Lines match on line_uid, so progress and cost recorded against earlier BOQ revisions stay on the same line.'],
            pagination: $pagination,
        );
    }
}
