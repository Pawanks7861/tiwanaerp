<?php

namespace App\Reports\Definitions;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Queries\Reports\ReceivablesQuery;
use App\Queries\Reports\Settlements;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReceivablesReport extends ReportDefinition
{
    public function __construct(private readonly ReceivablesQuery $receivables) {}

    public function key(): string
    {
        return 'client-receivables';
    }

    public function title(): string
    {
        return 'Client Receivables';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Certified RA bills less approved receipts as of a date, with aging and retention held.';
    }

    public function financialOnly(): bool
    {
        return true;
    }

    public function periodMode(): string
    {
        return 'asof';
    }

    public function filters(): array
    {
        return ['client', 'status', 'search'];
    }

    public function statusOptions(): array
    {
        return ['outstanding' => 'Outstanding only', 'settled' => 'Fully received'];
    }

    public function sorts(): array
    {
        return ['date' => 'invoice_date', 'outstanding' => 'outstanding', 'age' => 'age', 'client' => 'client'];
    }

    public function sortLabels(): array
    {
        return ['date' => 'Invoice date', 'outstanding' => 'Outstanding', 'age' => 'Age', 'client' => 'Client'];
    }

    public function defaultSort(): ?string
    {
        return 'date';
    }

    public function estimateRows(ReportContext $ctx): int
    {
        return DB::query()->fromSub($this->query($ctx), 'x')->count();
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $base = $this->query($ctx);
        $totals = $this->receivables->totals(clone $base);
        $live = $ctx->asOf() === $ctx->today;
        $query = $this->applySort(DB::query()->fromSub($base, 'r'), $ctx, 'id');

        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'project' => $r->project_code,
            'client' => $r->client,
            'invoice' => $r->ra_sequence ? "{$r->invoice_number} (RA {$r->ra_sequence})" : $r->invoice_number,
            'url' => route('projects.ra-bills.show', [$r->project_id, $r->id]),
            'date' => self::day($r->invoice_date),
            'age' => max(0, (int) $r->age),
            'bucket' => Settlements::bucket(max(0, (int) $r->age)),
            'status' => self::enumLabel(ClientInvoiceStatus::class, $r->status),
            'invoice_total' => Num::money($r->invoice_total),
            'net_payable' => Num::money($r->net_payable),
            'retention_held' => Num::money($r->retention_held),
            'due' => Num::money($r->due),
            'received' => Num::money($r->received),
            'outstanding' => Num::money($r->outstanding),
            'flag' => $live && ! Num::dec($r->received_amount)->equals(Num::dec($r->received)) ? 'Received cache differs from allocations' : null,
        ]);

        return new ReportResult(
            columns: [
                self::col('invoice', 'RA bill', 'code', ['link' => true]),
                self::col('project', 'Project', 'code'),
                self::col('client', 'Client'),
                self::col('date', 'Date', 'date'),
                self::col('age', 'Age (days)', 'number', ['mobile' => false]),
                self::col('bucket', 'Bucket', 'text', ['mobile' => false]),
                self::col('status', 'Status', 'status', ['mobile' => false]),
                self::money('invoice_total', 'Invoice total', ['mobile' => false]),
                self::money('net_payable', 'Net payable', ['mobile' => false]),
                self::money('retention_held', 'Retention held', ['mobile' => false]),
                self::money('due', 'Due'),
                self::money('received', 'Received'),
                self::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: ['invoice' => 'Total ('.$totals['count'].')', 'invoice_total' => $totals['invoice_total']->toMoney(), 'net_payable' => $totals['net_payable']->toMoney(),
                'retention_held' => $totals['retention_held']->toMoney(), 'due' => $totals['due']->toMoney(), 'received' => $totals['received']->toMoney(),
                'outstanding' => $totals['outstanding']->toMoney()],
            cards: [
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => $totals['outstanding']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'warning'],
                ['key' => 'b0', 'label' => '0–30 days', 'value' => $totals['b0_30']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b31', 'label' => '31–60 days', 'value' => $totals['b31_60']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b61', 'label' => '61–90 days', 'value' => $totals['b61_90']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b90', 'label' => '90+ days', 'value' => $totals['b90_plus']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'danger'],
                ['key' => 'retention', 'label' => 'Retention held', 'value' => $totals['retention_held']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
            ],
            notes: [
                'Due = net payable + retention released (approved releases). Received = allocations of approved receipts dated on or before the as-of date; cancelled receipts are excluded.',
                'Aging runs from the invoice date (RA bills carry no due date).',
            ],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->receivables->base($ctx->companyId(), $ctx->projectIds, $ctx->asOf(), [
            'client_id' => $ctx->filter('client_id'), 'status' => $ctx->filter('status'), 'search' => $ctx->filter('search'),
        ]);
    }
}
