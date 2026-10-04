<?php

namespace App\Reports\Definitions;

use App\Enums\Inventory\StockTxnType;
use App\Queries\Reports\StockQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StockLedgerReport extends ReportDefinition
{
    public function __construct(private readonly StockQuery $stock) {}

    public function key(): string
    {
        return 'stock-ledger';
    }

    public function title(): string
    {
        return 'Stock Ledger';
    }

    public function category(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Every stock movement in the period with running quantity and value per store and material.';
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function filters(): array
    {
        return ['warehouse', 'material', 'txn_type', 'search'];
    }

    public function choiceOptions(): array
    {
        return ['txn_type' => array_column(StockTxnType::options(), 'label', 'value')];
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return $this->query($ctx)->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $query = $this->query($ctx)->orderBy('x.txn_date')->orderBy('x.id');
        [$page, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => $r);
        $docs = $this->stock->documents($page);
        $visible = array_flip($ctx->projectIds);

        $rows = array_map(function ($r) use ($docs, $visible) {
            $doc = $docs["{$r->source_type}:{$r->source_id}"] ?? null;
            $qtyIn = Num::dec($r->qty_in);

            return [
                'date' => self::day($r->txn_date),
                'warehouse' => $r->warehouse_code,
                'material' => trim("{$r->material_code} — {$r->material}", ' —'),
                'type' => self::enumLabel(StockTxnType::class, $r->txn_type),
                'document' => $doc['number'] ?? null,
                'url' => $doc && $doc['project_id'] && isset($visible[$doc['project_id']]) ? route($doc['route'], [$doc['project_id'], $doc['id']]) : null,
                'qty_in' => $qtyIn->isZero() ? null : $qtyIn->toQuantity(),
                'qty_out' => Num::dec($r->qty_out)->isZero() ? null : Num::qty($r->qty_out),
                'running_qty' => Num::qty($r->running_qty),
                'unit_cost' => Num::rate($r->unit_cost),
                'value' => Num::money($r->signed_value),
                'running_value' => Num::money($r->running_value),
                'remarks' => $r->remarks,
            ];
        }, $page);

        $totals = DB::query()->fromSub($this->query($ctx), 't')
            ->selectRaw('COUNT(*) as cnt, SUM(qty_in) as qty_in, SUM(qty_out) as qty_out, SUM(signed_value) as value')->first();

        return new ReportResult(
            columns: [
                self::col('date', 'Date', 'date'),
                self::col('material', 'Material'),
                self::col('warehouse', 'Store', 'code', ['mobile' => false]),
                self::col('type', 'Movement', 'status'),
                self::col('document', 'Document', 'code', ['link' => true, 'mobile' => false]),
                self::col('qty_in', 'In', 'qty'),
                self::col('qty_out', 'Out', 'qty'),
                self::col('running_qty', 'Balance qty', 'qty'),
                self::col('unit_cost', 'Unit cost', 'rate', ['sensitive' => 'valuation', 'mobile' => false]),
                self::col('value', 'Value (±)', 'money', ['sensitive' => 'valuation', 'mobile' => false]),
                self::col('running_value', 'Balance value', 'money', ['sensitive' => 'valuation', 'mobile' => false]),
                self::col('remarks', 'Remarks', 'text', ['mobile' => false]),
            ],
            rows: $rows,
            totals: ['date' => 'Total ('.(int) ($totals->cnt ?? 0).')', 'qty_in' => Num::qty($totals->qty_in ?? null), 'qty_out' => Num::qty($totals->qty_out ?? null),
                'value' => Num::money($totals->value ?? null)],
            notes: ['Running balances include all movements before the period. Reversal rows move stock in the opposite direction of the row they cancel. Totals across different materials mix units — filter by material for meaningful quantity totals.'],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        $ids = $this->stock->warehouseIds($ctx->companyId(), $ctx->projectIds, $ctx->project === null && ! $ctx->filter('project_id'));
        if ($ctx->filter('warehouse_id')) {
            $ids = array_values(array_intersect($ids, [(int) $ctx->filter('warehouse_id')]));
        }

        return $this->stock->ledger($ctx->companyId(), $ids, (string) $ctx->from, $ctx->to, [
            'material_id' => $ctx->filter('material_id'), 'txn_type' => $ctx->filter('txn_type'), 'search' => $ctx->filter('search'),
        ]);
    }
}
