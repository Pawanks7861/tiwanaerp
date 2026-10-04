<?php

namespace App\Reports\Definitions;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Queries\Reports\ProcurementQuery;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ProcurementReport extends ReportDefinition
{
    public function __construct(private readonly ProcurementQuery $procurement) {}

    public function key(): string
    {
        return 'procurement-summary';
    }

    public function title(): string
    {
        return 'Purchase Order Summary';
    }

    public function category(): string
    {
        return 'procurement';
    }

    public function description(): string
    {
        return 'Purchase orders in the period with ordered, received, billed and open value per PO.';
    }

    public function permissions(): array
    {
        return ['purchase.view'];
    }

    public function filters(): array
    {
        return ['vendor', 'status', 'search'];
    }

    public function statusOptions(): array
    {
        return collect(PurchaseOrderStatus::cases())->reject(fn ($s) => in_array($s->value, ['draft', 'rejected'], true))
            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    }

    public function sorts(): array
    {
        return ['date' => 'po_date', 'value' => 'taxable_amount', 'open' => 'open_value', 'vendor' => 'vendor'];
    }

    public function sortLabels(): array
    {
        return ['date' => 'PO date', 'value' => 'Value', 'open' => 'Open value', 'vendor' => 'Vendor'];
    }

    public function sortSensitivity(): array
    {
        return ['value' => 'financial', 'open' => 'financial'];
    }

    public function defaultSort(): ?string
    {
        return 'date';
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return $this->query($ctx)->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = $this->query($ctx);
        $totals = $this->procurement->totals(clone $base);
        $query = $this->applySort(DB::query()->fromSub($base, 'p'), $ctx, 'id');

        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'po' => $r->po_number,
            'url' => route('projects.purchase-orders.show', [$r->project_id, $r->id]),
            'project' => $r->project_code,
            'vendor' => $r->vendor,
            'date' => self::day($r->po_date),
            'delivery' => self::day($r->delivery_date),
            'status' => self::enumLabel(PurchaseOrderStatus::class, $r->status),
            'items' => (int) $r->items,
            'taxable_amount' => Num::money($r->taxable_amount),
            'tax_amount' => Num::money($r->tax_amount),
            'grand_total' => Num::money($r->grand_total),
            'received_value' => Num::money($r->received_value),
            'billed' => Num::money($r->billed),
            'open_value' => Num::money($r->open_value),
        ]);

        return new ReportResult(
            columns: [
                self::col('po', 'PO', 'code', ['link' => true]),
                self::col('project', 'Project', 'code'),
                self::col('vendor', 'Vendor'),
                self::col('date', 'Date', 'date'),
                self::col('delivery', 'Delivery', 'date', ['mobile' => false]),
                self::col('status', 'Status', 'status'),
                self::col('items', 'Lines', 'number', ['mobile' => false]),
                self::money('taxable_amount', 'Value (excl. GST)'),
                self::money('tax_amount', 'GST', ['mobile' => false]),
                self::money('grand_total', 'Grand total', ['mobile' => false]),
                self::money('received_value', 'Received value', ['mobile' => false]),
                self::money('billed', 'Billed', ['mobile' => false]),
                self::money('open_value', 'Open value'),
            ],
            rows: $rows,
            totals: ['po' => 'Total ('.$totals['count'].')'] + array_diff_key($totals, ['count' => true]),
            cards: [
                ['key' => 'count', 'label' => 'Purchase orders', 'value' => $totals['count'], 'type' => 'number'],
                ['key' => 'value', 'label' => 'Purchase value (excl. GST)', 'value' => $totals['taxable_amount'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'received', 'label' => 'Received value', 'value' => $totals['received_value'], 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'open', 'label' => 'Open (committed)', 'value' => $totals['open_value'], 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: ['Received value = accepted quantity on approved GRNs × the PO line taxable rate. Open value applies to approved, partially received and received POs only; closed and cancelled POs commit nothing more.'],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->procurement->orders($ctx->companyId(), $ctx->projectIds, (string) $ctx->from, $ctx->to, [
            'vendor_id' => $ctx->filter('vendor_id'), 'status' => $ctx->filter('status'), 'search' => $ctx->filter('search'),
        ]);
    }
}
