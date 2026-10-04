<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\StockQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock per store and material from the stock ledger as of a date. When run for today it puts
 * the stock_balances cache beside the ledger and flags drift; value / average cost need
 * inventory.view_valuation.
 */
final class StockSummaryReport extends ReportDefinition
{
    public function __construct(private readonly StockQuery $stock) {}

    public function key(): string
    {
        return 'stock-summary';
    }

    public function title(): string
    {
        return 'Stock Summary';
    }

    public function category(): string
    {
        return 'inventory';
    }

    public function description(): string
    {
        return 'Quantity and value per store and material from the stock ledger, with low-stock, slow-moving and cache-drift flags.';
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function filters(): array
    {
        return ['warehouse', 'category', 'material', 'status', 'search'];
    }

    public function statusOptions(): array
    {
        return ['low' => 'At or below reorder level', 'slow' => 'Slow moving (no issue in 90 days)', 'drift' => 'Ledger / cache mismatch'];
    }

    public function sorts(): array
    {
        return ['material' => 'material', 'qty' => 'qty', 'value' => 'value', 'last' => 'last_txn'];
    }

    public function sortLabels(): array
    {
        return ['material' => 'Material', 'qty' => 'Quantity', 'value' => 'Value', 'last' => 'Last movement'];
    }

    public function sortSensitivity(): array
    {
        return ['value' => 'valuation'];
    }

    public function defaultSort(): ?string
    {
        return 'material';
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return DB::query()->fromSub($this->query($ctx), 'x')->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $live = $ctx->asOf() === $ctx->today;
        $slowBefore = date('Y-m-d', strtotime($ctx->asOf().' -90 days'));
        $query = $this->applySort(DB::query()->fromSub($this->query($ctx), 's'), $ctx, 'warehouse_code')->orderBy('material_id');

        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, function ($r) use ($live, $slowBefore) {
            $qty = Num::dec($r->qty);
            $value = Num::dec($r->value);
            $drift = $live && (! $qty->equals(Num::dec($r->cache_qty)) || ! Num::dec($value->toMoney())->equals(Num::dec($r->cache_value)));
            $low = Num::dec($r->reorder_level)->isPositive() && $qty->lessThanOrEqual(Num::dec($r->reorder_level));
            $slow = $qty->isPositive() && ($r->last_out === null || substr((string) $r->last_out, 0, 10) < $slowBefore);

            return [
                'material' => trim("{$r->material_code} — {$r->material}", ' —'),
                'warehouse' => $r->warehouse_code,
                'project' => $r->project_code ?? 'Central',
                'category' => $r->category,
                'unit' => $r->unit,
                'qty' => $qty->toQuantity(),
                'reorder_level' => Num::dec($r->reorder_level)->isPositive() ? Num::qty($r->reorder_level) : null,
                'avg_cost' => $qty->isPositive() ? $value->dividedBy($qty)->toRate() : null,
                'value' => $value->toMoney(),
                'cache_qty' => $live ? Num::qty($r->cache_qty) : null,
                'last_txn' => self::day($r->last_txn),
                'last_out' => self::day($r->last_out),
                'flag' => $drift ? 'Cache differs from ledger' : ($low ? 'Low stock' : ($slow ? 'Slow moving' : null)),
            ];
        });

        $totals = DB::query()->fromSub($this->query($ctx), 't')->selectRaw('COUNT(*) as cnt, SUM(value) as value')->first();
        $flags = DB::query()->fromSub($this->stock->summary($ctx->companyId(), $this->warehouses($ctx), $ctx->asOf(), ['status' => 'low']), 'l')->count();
        $driftCount = $live ? DB::query()->fromSub($this->stock->summary($ctx->companyId(), $this->warehouses($ctx), $ctx->asOf(), ['status' => 'drift']), 'd')->count() : null;

        return new ReportResult(
            columns: [
                self::col('material', 'Material'),
                self::col('warehouse', 'Store', 'code'),
                self::col('project', 'Project', 'code', ['mobile' => false]),
                self::col('category', 'Category', 'text', ['mobile' => false]),
                self::col('unit', 'Unit', 'text', ['mobile' => false]),
                self::col('qty', 'Quantity (ledger)', 'qty'),
                self::col('reorder_level', 'Reorder level', 'qty', ['mobile' => false]),
                self::col('avg_cost', 'Avg cost', 'rate', ['sensitive' => 'valuation', 'mobile' => false]),
                self::col('value', 'Value', 'money', ['sensitive' => 'valuation']),
                self::col('cache_qty', 'Quantity (cache)', 'qty', ['mobile' => false]),
                self::col('last_txn', 'Last movement', 'date', ['mobile' => false]),
                self::col('last_out', 'Last issue / out', 'date', ['mobile' => false]),
            ],
            rows: $rows,
            totals: ['material' => 'Total ('.(int) ($totals->cnt ?? 0).')', 'value' => Num::money($totals->value ?? null)],
            cards: array_values(array_filter([
                ['key' => 'items', 'label' => 'Stock lines', 'value' => (int) ($totals->cnt ?? 0), 'type' => 'number'],
                ['key' => 'value', 'label' => 'Stock value', 'value' => Num::money($totals->value ?? null), 'type' => 'money', 'sensitive' => 'valuation'],
                ['key' => 'low', 'label' => 'Low stock', 'value' => $flags, 'type' => 'number', 'tone' => $flags > 0 ? 'warning' : 'success'],
                $driftCount === null ? null : ['key' => 'drift', 'label' => 'Ledger / cache mismatches', 'value' => $driftCount, 'type' => 'number', 'tone' => $driftCount > 0 ? 'danger' : 'success'],
            ])),
            notes: [
                'Quantity and value come from the stock ledger. The cache column (stock_balances) is shown only for today\'s stock; any difference is flagged.',
                'Company view covers stores of your projects plus central stores.',
            ],
            pagination: $pagination,
        );
    }

    /**
     * @return list<int>
     */
    private function warehouses(ReportContext $ctx): array
    {
        $ids = $this->stock->warehouseIds($ctx->companyId(), $ctx->projectIds, $ctx->project === null && ! $ctx->filter('project_id'));
        $selected = $ctx->filter('warehouse_id');

        return $selected ? array_values(array_intersect($ids, [(int) $selected])) : $ids;
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->stock->summary($ctx->companyId(), $this->warehouses($ctx), $ctx->asOf(), [
            'material_id' => $ctx->filter('material_id'), 'category_id' => $ctx->filter('category_id'),
            'search' => $ctx->filter('search'), 'status' => $ctx->filter('status'),
        ], $ctx->asOf() === $ctx->today);
    }
}
