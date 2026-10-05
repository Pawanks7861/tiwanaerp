<?php

use App\Enums\CostHead;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Finance\VendorBillStatus;
use App\Enums\Integrations\TallySyncStatus;
use App\Enums\Procurement\TaxType;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Integrations\Tally\TallyMappingCatalog;
use App\Integrations\Tally\TallySyncService;
use App\Models\Crm\Client;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Payment;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Finance\VendorBill;
use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallyLedgerMapping;
use App\Models\Integrations\TallySyncRecord;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\TaxRate;
use App\Models\Subcontract\SubcontractorBill;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ClientInvoiceService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

beforeEach(function () {
    $this->setUpResources();
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->inCompany($this->company, function () {
        $this->client = Client::query()->create(['code' => 'CL001', 'company_name' => 'Skyline Developers', 'state_code' => '27']);
        $this->company->forceFill(['state_code' => '27'])->save();
        $this->project->forceFill(['client_id' => $this->client->id, 'state_code' => '27'])->save();
        $this->gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
    });
});

function tallyConnection($test, array $overrides = []): TallyConnection
{
    return $test->inCompany($test->company, function () use ($overrides) {
        $connection = TallyConnection::query()->first() ?? new TallyConnection;
        $connection->forceFill($overrides + [
        'enabled' => true,
        'transport' => 'direct',
        'protocol' => 'http',
        'format' => 'xml',
        'host' => '10.0.0.8',
        'port' => 9000,
        'tally_company_name' => 'Test Books',
        'timeout_seconds' => 5,
        'auto_sync' => false,
        'sync_approved_transactions' => true,
        'dry_run' => false,
            'cost_centres_enabled' => false,
        ])->save();

        return $connection;
    });
}

function tallyLedgers($test, array $parties = []): void
{
    $test->inCompany($test->company, function () use ($parties) {
        foreach (TallyMappingCatalog::systems() as $row) {
            TallyLedgerMapping::query()->forceCreate([
                'map_key' => TallyMappingCatalog::mapKey($row['key']),
                'mapping_type' => $row['type'],
                'source_type' => 'system',
                'source_key' => $row['key'],
                'tally_ledger_name' => $row['label'],
                'tally_parent_group' => $row['parent'],
                'auto_create_allowed' => false,
                'active' => true,
            ]);
        }
        foreach ($parties as $party) {
            TallyLedgerMapping::query()->forceCreate($party + [
                'auto_create_allowed' => false,
                'active' => true,
            ]);
        }
    });
}

function tallyCreatedXml(): string
{
    return '<ENVELOPE><CREATED>1</CREATED><ALTERED>0</ALTERED><DELETED>0</DELETED><ERRORS>0</ERRORS><GUID>guid-1</GUID><LASTVCHID>41</LASTVCHID></ENVELOPE>';
}

function tallyInvoice($test, array $overrides = []): ClientInvoice
{
    return $test->inCompany($test->company, fn () => ClientInvoice::query()->forceCreate($overrides + [
        'project_id' => $test->project->id,
        'client_id' => $test->client->id,
        'boq_id' => $test->boqLine->boq_id,
        'invoice_number' => 'INV-100',
        'ra_sequence' => 1,
        'invoice_date' => '2026-10-01',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'supplier_state' => '27',
        'place_of_supply_state' => '27',
        'tax_type' => TaxType::Intra,
        'gross_amount' => '100000.00',
        'cgst_amount' => '9000.00',
        'sgst_amount' => '9000.00',
        'igst_amount' => '0.00',
        'tax_amount' => '18000.00',
        'invoice_total' => '118000.00',
        'retention_amount' => '5000.00',
        'advance_recovery' => '10000.00',
        'tds_amount' => '1000.00',
        'other_deductions' => '0.00',
        'net_payable' => '102000.00',
        'status' => ClientInvoiceStatus::Certified,
    ]));
}

test('connection reports connected, mismatch, empty company, bad xml and transport failures', function () {
    tallyConnection($this);
    $tester = app(App\Integrations\Tally\TallyConnectionTester::class);
    $connection = $this->inCompany($this->company, fn () => TallyConnection::query()->first());

    $steps = [
        '<ENVELOPE><COMPANYNAME>Test Books</COMPANYNAME></ENVELOPE>',
        '<ENVELOPE><COMPANYNAME>Other Books</COMPANYNAME></ENVELOPE>',
        '<ENVELOPE></ENVELOPE>',
        '<not-xml',
    ];
    Http::fake(function () use (&$steps) {
        $next = array_shift($steps);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Http::response($next, 200);
    });

    expect($tester->test($connection)['message'])->toBe('Connected')
        ->and($tester->test($connection->fresh())['message'])->toBe('Company name mismatch')
        ->and($tester->test($connection->fresh())['message'])->toBe('Company not loaded')
        ->and($tester->test($connection->fresh())['message'])->toBe('Invalid response');
});

test('transport failures become timeout, wrong port and not reachable', function () {
    tallyConnection($this);
    $connection = $this->inCompany($this->company, fn () => TallyConnection::query()->first());
    $client = new App\Integrations\Tally\TallyXmlClient($connection);
    $transport = new ReflectionMethod($client, 'transport');
    $messages = [
        'cURL error 28: Operation timed out' => 'Timeout',
        'cURL error 7: Connection refused' => 'Wrong port',
        'Could not resolve host' => 'Tally not reachable',
    ];
    foreach ($messages as $curl => $message) {
        $exception = $transport->invoke($client, $curl);
        expect($exception->getMessage())->toBe($message);
    }
});

test('an import error in a 200 response does not mark the voucher synced', function () {
    tallyConnection($this);
    tallyLedgers($this, [[
        'map_key' => 'client:'.$this->client->id,
        'mapping_type' => 'client',
        'source_type' => 'client',
        'source_id' => $this->client->id,
        'tally_ledger_name' => 'Skyline Developers',
        'tally_parent_group' => 'Sundry Debtors',
    ]]);
    $invoice = tallyInvoice($this);
    Http::fake(['*' => Http::response('<ENVELOPE><CREATED>0</CREATED><ERRORS>1</ERRORS><LINEERROR>Ledger missing</LINEERROR></ENVELOPE>', 200)]);

    $record = $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice));

    expect($record->status)->toBe(TallySyncStatus::Failed)
        ->and($record->error_message)->toBe('Ledger missing')
        ->and($record->tally_guid)->toBeNull();
});

test('a certified invoice syncs once and a second sync does not post again', function () {
    tallyConnection($this);
    tallyLedgers($this, [[
        'map_key' => 'client:'.$this->client->id,
        'mapping_type' => 'client',
        'source_type' => 'client',
        'source_id' => $this->client->id,
        'tally_ledger_name' => 'Skyline Developers',
        'tally_parent_group' => 'Sundry Debtors',
    ]]);
    $invoice = tallyInvoice($this);
    Http::fake(['*' => Http::response(tallyCreatedXml(), 200)]);

    $this->actingInCompany($this->admin, $this->company)
        ->get(route('integrations.tally.documents.preview', ['client_invoice', $invoice->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('voucher.voucher_type', 'Sales')
            ->where('voucher.lines', fn ($lines) => tallyLinesMatch($lines, [
                'Skyline Developers' => ['102000.00', '0.00'],
                'Retention receivable' => ['5000.00', '0.00'],
                'TDS receivable' => ['1000.00', '0.00'],
                'Client advance' => ['10000.00', '0.00'],
                'Work income / sales' => ['0.00', '100000.00'],
                'Output CGST' => ['0.00', '9000.00'],
                'Output SGST' => ['0.00', '9000.00'],
            ])));

    $first = $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice->fresh()));
    $second = $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice->fresh()));

    expect($first->status)->toBe(TallySyncStatus::Synced)
        ->and($second->id)->toBe($first->id)
        ->and(TallySyncRecord::query()->withoutGlobalScopes()->count())->toBe(1);
    Http::assertSentCount(1);

    DB::table('client_invoices')->where('id', $invoice->id)->update([
        'net_payable' => '101000.00',
        'retention_amount' => '6000.00',
    ]);
    $changed = $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice->fresh()));
    expect($changed->status)->toBe(TallySyncStatus::Conflict)
        ->and($changed->error_message)->toBe('Previously synced document has changed');
    Http::assertSentCount(1);
});

test('a receipt, vendor bill and vendor payment each sync once with the stored amounts', function () {
    tallyConnection($this);
    tallyLedgers($this, [
        [
            'map_key' => 'client:'.$this->client->id,
            'mapping_type' => 'client',
            'source_type' => 'client',
            'source_id' => $this->client->id,
            'tally_ledger_name' => 'Skyline Developers',
            'tally_parent_group' => 'Sundry Debtors',
        ],
        [
            'map_key' => 'vendor:'.$this->hireVendor->id,
            'mapping_type' => 'vendor',
            'source_type' => 'vendor',
            'source_id' => $this->hireVendor->id,
            'tally_ledger_name' => 'Deccan Hire',
            'tally_parent_group' => 'Sundry Creditors',
        ],
    ]);
    Http::fake(['*' => Http::response(tallyCreatedXml(), 200)]);

    $receipt = $this->inCompany($this->company, fn () => Payment::query()->forceCreate([
        'project_id' => $this->project->id,
        'payment_number' => 'RCT-1',
        'direction' => PaymentDirection::Receipt,
        'party_type' => PaymentPartyType::Client,
        'party_id' => $this->client->id,
        'payment_date' => '2026-10-02',
        'mode' => 'bank_transfer',
        'amount' => '25000.50',
        'tds_amount' => '500.00',
        'status' => PaymentStatus::Approved,
    ]));
    $bill = $this->inCompany($this->company, fn () => VendorBill::query()->forceCreate([
        'project_id' => $this->project->id,
        'vendor_id' => $this->hireVendor->id,
        'bill_type' => 'direct',
        'cost_head' => CostHead::Material,
        'bill_number' => 'VB-1',
        'vendor_invoice_no' => 'DH/9',
        'vendor_invoice_date' => '2026-10-02',
        'place_of_supply_state' => '27',
        'tax_type' => TaxType::Intra,
        'subtotal' => '100000.00',
        'cgst_amount' => '9000.00',
        'sgst_amount' => '9000.00',
        'igst_amount' => '0.00',
        'total_amount' => '118000.00',
        'tds_amount' => '2000.00',
        'net_payable' => '116000.00',
        'status' => VendorBillStatus::Approved,
    ]));
    $payment = $this->inCompany($this->company, fn () => Payment::query()->forceCreate([
        'project_id' => $this->project->id,
        'payment_number' => 'PAY-1',
        'direction' => PaymentDirection::Payment,
        'party_type' => PaymentPartyType::Vendor,
        'party_id' => $this->hireVendor->id,
        'payment_date' => '2026-10-03',
        'mode' => 'bank_transfer',
        'amount' => '116000.00',
        'tds_amount' => '0.00',
        'status' => PaymentStatus::Approved,
    ]));

    $sync = app(TallySyncService::class);
    $this->inCompany($this->company, function () use ($sync, $receipt, $bill, $payment) {
        expect($sync->preview($receipt)->toArray()['lines'])->toEqualCanonicalizing(tallyExpected([
            'Bank' => ['25000.50', '0.00'],
            'TDS receivable' => ['500.00', '0.00'],
            'Skyline Developers' => ['0.00', '25500.50'],
        ]))
            ->and($sync->preview($bill)->toArray()['lines'])->toEqualCanonicalizing(tallyExpected([
                'Material expense' => ['100000.00', '0.00'],
                'Input CGST' => ['9000.00', '0.00'],
                'Input SGST' => ['9000.00', '0.00'],
                'TDS payable' => ['0.00', '2000.00'],
                'Deccan Hire' => ['0.00', '116000.00'],
            ]))
            ->and($sync->preview($payment)->toArray()['lines'])->toEqualCanonicalizing(tallyExpected([
                'Deccan Hire' => ['116000.00', '0.00'],
                'Bank' => ['0.00', '116000.00'],
            ]));
        $sync->enqueue($receipt);
        $sync->enqueue($receipt->fresh());
        $sync->enqueue($bill);
        $sync->enqueue($bill->fresh());
        $sync->enqueue($payment);
        $sync->enqueue($payment->fresh());
    });

    expect(TallySyncRecord::query()->withoutGlobalScopes()->where('status', 'synced')->count())->toBe(3);
    Http::assertSentCount(3);
});

test('a certified subcontract bill keeps retention, tds and advance on their own ledgers', function () {
    $order = $this->approveWorkOrder($this->makeWorkOrder());
    tallyConnection($this);
    tallyLedgers($this, [[
        'map_key' => 'subcontractor:'.$this->subcontractor->id,
        'mapping_type' => 'subcontractor',
        'source_type' => 'subcontractor',
        'source_id' => $this->subcontractor->id,
        'tally_ledger_name' => 'Shree Formwork',
        'tally_parent_group' => 'Sundry Creditors',
    ]]);
    $bill = $this->inCompany($this->company, fn () => SubcontractorBill::query()->forceCreate([
        'project_id' => $this->project->id,
        'work_order_id' => $order->id,
        'subcontractor_id' => $this->subcontractor->id,
        'bill_number' => 'SCB-1',
        'bill_date' => '2026-10-04',
        'period_from' => '2026-10-01',
        'period_to' => '2026-10-04',
        'gross_amount' => '50000.00',
        'tax_amount' => '9000.00',
        'retention_amount' => '2500.00',
        'advance_recovery' => '5000.00',
        'tds_amount' => '1000.00',
        'other_deductions' => '500.00',
        'net_payable' => '50000.00',
        'status' => SubcontractorBillStatus::Certified,
    ]));

    $lines = $this->inCompany($this->company, fn () => app(TallySyncService::class)->preview($bill)->toArray()['lines']);
    expect($lines)->toEqualCanonicalizing(tallyExpected([
        'Subcontract expenses' => ['50000.00', '0.00'],
        'Input GST (single amount)' => ['9000.00', '0.00'],
        'Retention payable' => ['0.00', '2500.00'],
        'TDS payable' => ['0.00', '1000.00'],
        'Subcontract advance' => ['0.00', '5000.00'],
        'Other deductions' => ['0.00', '500.00'],
        'Shree Formwork' => ['0.00', '50000.00'],
    ]));
});

test('draft and rejected documents cannot sync and a missing ledger blocks the voucher', function () {
    tallyConnection($this);
    $draft = tallyInvoice($this, ['status' => ClientInvoiceStatus::Draft, 'invoice_number' => null, 'ra_sequence' => 2]);
    $rejected = tallyInvoice($this, ['status' => ClientInvoiceStatus::Rejected, 'invoice_number' => null, 'ra_sequence' => 3]);
    $payment = $this->inCompany($this->company, fn () => Payment::query()->forceCreate([
        'project_id' => $this->project->id,
        'payment_number' => 'RCT-DRAFT',
        'direction' => PaymentDirection::Receipt,
        'party_type' => PaymentPartyType::Client,
        'party_id' => $this->client->id,
        'payment_date' => '2026-10-02',
        'mode' => 'cash',
        'amount' => '10.00',
        'status' => PaymentStatus::Draft,
    ]));

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('integrations.tally.documents.sync', ['client_invoice', $draft->id]))
        ->assertSessionHasErrors('tally');
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('integrations.tally.documents.sync', ['client_invoice', $rejected->id]))
        ->assertSessionHasErrors('tally');
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('integrations.tally.documents.sync', ['payment', $payment->id]))
        ->assertSessionHasErrors('tally');

    $certified = tallyInvoice($this);
    $this->actingInCompany($this->admin, $this->company)
        ->get(route('integrations.tally.documents.preview', ['client_invoice', $certified->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('error', 'Missing Tally ledger mapping: Client'));
    expect(TallySyncRecord::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('another company cannot use these settings, mappings or history', function () {
    tallyConnection($this);
    tallyLedgers($this);
    $invoice = tallyInvoice($this);
    Http::fake(['*' => Http::response(tallyCreatedXml(), 200)]);
    $record = $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice));

    $other = $this->createCompany(['name' => 'Other Co', 'code' => 'OTHER']);
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($otherAdmin, $other)
        ->get(route('integrations.tally.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('connection.host', '')->where('connection.tally_company_name', ''));

    $this->actingInCompany($otherAdmin, $other)
        ->get(route('integrations.tally.mappings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('parties', [])->where('systems.0.tally_ledger_name', ''));

    $this->actingInCompany($otherAdmin, $other)
        ->get(route('integrations.tally.history.show', $record))
        ->assertNotFound();

    $this->actingInCompany($otherAdmin, $other)
        ->post(route('integrations.tally.documents.sync', ['client_invoice', $invoice->id]))
        ->assertNotFound();
});

test('a site engineer cannot open tally and an accountant cannot manage settings or read the raw response', function () {
    tallyConnection($this);
    $record = $this->inCompany($this->company, fn () => TallySyncRecord::query()->forceCreate([
        'source_type' => 'client_invoice',
        'source_id' => 1,
        'action' => 'export',
        'erp_reference' => 'INV-1',
        'status' => TallySyncStatus::Synced,
        'response_payload' => ['guid' => 'secret-guid'],
        'request_payload' => ['lines' => [], 'narration' => 'Imported'],
    ]));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('integrations.tally.edit'))
        ->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)
        ->put(route('integrations.tally.update'), tallySettings())
        ->assertForbidden();

    $this->actingInCompany($this->accountant, $this->company)
        ->get(route('integrations.tally.edit'))
        ->assertOk();
    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('integrations.tally.update'), tallySettings())
        ->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)
        ->get(route('integrations.tally.history.show', $record))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('record.response', null)->where('can.manage', false));
});

test('a public tally host is rejected', function () {
    $this->actingInCompany($this->admin, $this->company)
        ->put(route('integrations.tally.update'), tallySettings(['host' => '8.8.8.8']))
        ->assertSessionHasErrors('host');
});

test('certifying an invoice still succeeds when tally is offline and a later retry syncs once', function () {
    tallyConnection($this, ['auto_sync' => true, 'sync_approved_transactions' => true]);
    tallyLedgers($this, [[
        'map_key' => 'client:'.$this->client->id,
        'mapping_type' => 'client',
        'source_type' => 'client',
        'source_id' => $this->client->id,
        'tally_ledger_name' => 'Skyline Developers',
        'tally_parent_group' => 'Sundry Debtors',
    ]]);
    $this->postProgress('8', now()->subDays(3)->toDateString());
    $this->app->instance(App\Integrations\Tally\TallyClientFactory::class, new class extends App\Integrations\Tally\TallyClientFactory
    {
        public function make(App\Models\Integrations\TallyConnection $connection): App\Integrations\Tally\TallyClientInterface
        {
            return new class($connection) implements App\Integrations\Tally\TallyClientInterface
            {
                public function __construct(private App\Models\Integrations\TallyConnection $connection) {}

                public function endpoint(): string
                {
                    return $this->connection->endpoint();
                }

                public function postXml(string $xml): string
                {
                    throw new App\Integrations\Tally\TallyTransportException('Wrong port', 'wrong_port');
                }
            };
        }
    });

    $invoice = $this->inCompany($this->company, function () {
        $service = app(ClientInvoiceService::class);
        $invoice = $service->create($this->project, [
            'invoice_date' => now()->toDateString(),
            'period_from' => now()->subDays(10)->toDateString(),
            'period_to' => now()->toDateString(),
            'tax_rate_id' => $this->gst18,
            'retention_percent' => '0',
            'tds_percent' => '0',
            'advance_recovery' => '0',
            'other_deductions' => '0',
            'items' => [['boq_item_id' => $this->boqLine->id, 'current_qty' => '5']],
        ], $this->billing);
        $service->submit($invoice, $this->billing);

        return $invoice->fresh();
    });

    $before = tallyCounts();
    $approvals = app(ApprovalService::class);
    $this->actingInCompany($this->pm, $this->company);
    $step = $this->inCompany($this->company, fn () => $approvals->approve($invoice->fresh()->pendingApprovalRequest(), $this->pm));
    $this->actingInCompany($this->director, $this->company);
    $this->inCompany($this->company, fn () => $approvals->approve($step->fresh(), $this->director));

    $invoice->refresh();
    $record = $this->inCompany($this->company, fn () => TallySyncRecord::query()->where('source_id', $invoice->id)->first());
    expect($invoice->status)->toBe(ClientInvoiceStatus::Certified)
        ->and($record->status)->toBe(TallySyncStatus::Pending)
        ->and(tallyCounts())->toBe($before)
        ->and(ClientInvoice::query()->withoutGlobalScopes()->count())->toBe(1);

    $this->app->forgetInstance(App\Integrations\Tally\TallyClientFactory::class);
    Http::fake(['*' => Http::response(tallyCreatedXml(), 200)]);
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('integrations.tally.history.retry', $record))
        ->assertRedirect();
    $record->refresh();
    expect($record->status)->toBe(TallySyncStatus::Synced)
        ->and(ClientInvoice::query()->withoutGlobalScopes()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('sync does not write operational ledgers', function () {
    tallyConnection($this);
    tallyLedgers($this, [[
        'map_key' => 'client:'.$this->client->id,
        'mapping_type' => 'client',
        'source_type' => 'client',
        'source_id' => $this->client->id,
        'tally_ledger_name' => 'Skyline Developers',
        'tally_parent_group' => 'Sundry Debtors',
    ]]);
    $invoice = tallyInvoice($this);
    $before = tallyCounts();
    Http::fake(['*' => Http::response(tallyCreatedXml(), 200)]);
    $this->inCompany($this->company, fn () => app(TallySyncService::class)->enqueue($invoice));

    expect(tallyCounts())->toBe($before);
});

function tallyCounts(): array
{
    return [
        'cost' => ProjectCostEntry::query()->withoutGlobalScopes()->count(),
        'stock' => StockTransaction::query()->withoutGlobalScopes()->count(),
        'payments' => Payment::query()->withoutGlobalScopes()->count(),
        'invoices' => ClientInvoice::query()->withoutGlobalScopes()->count(),
        'bills' => VendorBill::query()->withoutGlobalScopes()->count(),
    ];
}

function tallySettings(array $overrides = []): array
{
    return $overrides + [
        'enabled' => true,
        'transport' => 'direct',
        'protocol' => 'http',
        'format' => 'xml',
        'host' => '10.0.0.8',
        'port' => 9000,
        'tally_company_name' => 'Test Books',
        'timeout_seconds' => 15,
        'auto_sync' => false,
        'sync_approved_transactions' => true,
        'dry_run' => true,
        'cost_centres_enabled' => false,
    ];
}

function tallyLinesMatch(mixed $lines, array $expected): bool
{
    return tallyExpected($expected) == collect($lines)->map(fn ($line) => [
        'ledger' => $line['ledger'],
        'debit' => $line['debit'],
        'credit' => $line['credit'],
    ])->sortBy('ledger')->values()->all();
}

function tallyExpected(array $rows): array
{
    $lines = [];
    foreach ($rows as $ledger => [$debit, $credit]) {
        $lines[] = ['ledger' => $ledger, 'debit' => $debit, 'credit' => $credit];
    }
    usort($lines, fn ($a, $b) => $a['ledger'] <=> $b['ledger']);

    return $lines;
}
