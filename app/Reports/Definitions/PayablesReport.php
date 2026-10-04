<?php

namespace App\Reports\Definitions;

use App\Queries\Reports\PayablesQuery;
use App\Queries\Reports\Settlements;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Support\Reports\Num;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PayablesReport extends ReportDefinition
{
    private const ROUTES = [
        'vendor' => 'projects.vendor-bills.show',
        'subcontract' => 'projects.subcontractor-bills.show',
        'labour' => 'projects.labour-payments.show',
    ];

    public function __construct(private readonly PayablesQuery $payables) {}

    public function key(): string
    {
        return 'payables';
    }

    public function title(): string
    {
        return 'Vendor, Subcontract & Labour Payables';
    }

    public function category(): string
    {
        return 'finance';
    }

    public function description(): string
    {
        return 'Approved vendor bills, certified subcontractor bills and approved labour batches less approved payments, with aging.';
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
        return ['view', 'vendor', 'subcontractor', 'status', 'search'];
    }

    public function viewOptions(): array
    {
        return ['' => 'All payables'] + PayablesQuery::KINDS;
    }

    public function statusOptions(): array
    {
        return ['outstanding' => 'Outstanding only', 'settled' => 'Fully paid'];
    }

    public function sorts(): array
    {
        return ['date' => 'doc_date', 'outstanding' => 'outstanding', 'age' => 'age', 'party' => 'party'];
    }

    public function sortLabels(): array
    {
        return ['date' => 'Document date', 'outstanding' => 'Outstanding', 'age' => 'Age', 'party' => 'Party'];
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
        $totals = $this->payables->totals(clone $base);
        $pending = $this->payables->pendingVendorBills($ctx->companyId(), $ctx->projectIds, $ctx->asOf());
        $query = $this->applySort(DB::query()->fromSub($base, 'p'), $ctx, 'id')->orderBy('kind');

        [$rows, $pagination] = $this->rows($query, $ctx, $paginate, fn ($r) => [
            'kind' => PayablesQuery::KINDS[$r->kind] ?? $r->kind,
            'project' => $r->project_code,
            'party' => $r->party,
            'document' => $r->reference ? "{$r->document} / {$r->reference}" : $r->document,
            'url' => route(self::ROUTES[$r->kind], [$r->project_id, $r->id]),
            'date' => self::day($r->doc_date),
            'age_from' => self::day($r->age_from),
            'age' => max(0, (int) $r->age),
            'bucket' => Settlements::bucket(max(0, (int) $r->age)),
            'gross' => Num::money($r->gross),
            'deductions' => Num::money($r->deductions),
            'due' => Num::money($r->due),
            'settled' => Num::money($r->settled),
            'outstanding' => Num::money($r->outstanding),
        ]);

        return new ReportResult(
            columns: [
                self::col('document', 'Document', 'code', ['link' => true]),
                self::col('kind', 'Type', 'status'),
                self::col('project', 'Project', 'code'),
                self::col('party', 'Party'),
                self::col('date', 'Date', 'date', ['mobile' => false]),
                self::col('age_from', 'Aged from', 'date', ['mobile' => false]),
                self::col('age', 'Age (days)', 'number', ['mobile' => false]),
                self::col('bucket', 'Bucket', 'text', ['mobile' => false]),
                self::money('gross', 'Gross', ['mobile' => false]),
                self::money('deductions', 'Deductions', ['mobile' => false]),
                self::money('due', 'Due'),
                self::money('settled', 'Paid'),
                self::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: ['document' => 'Total ('.$totals['count'].')', 'gross' => $totals['gross']->toMoney(), 'deductions' => $totals['deductions']->toMoney(),
                'due' => $totals['due']->toMoney(), 'settled' => $totals['settled']->toMoney(), 'outstanding' => $totals['outstanding']->toMoney()],
            cards: [
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => $totals['outstanding']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'warning'],
                ['key' => 'b0', 'label' => '0–30 days', 'value' => $totals['b0_30']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b31', 'label' => '31–60 days', 'value' => $totals['b31_60']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b61', 'label' => '61–90 days', 'value' => $totals['b61_90']->toMoney(), 'type' => 'money', 'sensitive' => 'financial'],
                ['key' => 'b90', 'label' => '90+ days', 'value' => $totals['b90_plus']->toMoney(), 'type' => 'money', 'sensitive' => 'financial', 'tone' => 'danger'],
                ['key' => 'pending', 'label' => 'Vendor bills awaiting approval ('.$pending['count'].')', 'value' => $pending['amount']->toMoney(), 'type' => 'money',
                    'sensitive' => 'financial', 'hint' => 'Not yet payable'],
            ],
            notes: [
                'Vendor: approved bills, due = net payable after TDS, aged from the due date (else the vendor invoice date).',
                'Subcontract: certified bills, due = net payable + released retention. Labour: approved batches (batches marked paid directly in Labour are settled outside Finance).',
                'Paid = allocations of approved payments dated on or before the as-of date.',
            ],
            pagination: $pagination,
        );
    }

    private function query(ReportContext $ctx): Builder
    {
        return $this->payables->base($ctx->companyId(), $ctx->projectIds, $ctx->asOf(), [
            'kind' => $ctx->filter('view'), 'vendor_id' => $ctx->filter('vendor_id'), 'subcontractor_id' => $ctx->filter('subcontractor_id'),
            'status' => $ctx->filter('status'), 'search' => $ctx->filter('search'),
        ]);
    }
}
