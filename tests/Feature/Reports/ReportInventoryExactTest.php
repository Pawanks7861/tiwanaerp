<?php

use App\Models\Finance\Payment;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Finance\VendorBill;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\TaxRate;
use App\Models\SiteExecution\SiteDiary;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\VendorBillService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->accountant = $this->createMember($this->company, DefaultRoles::ACCOUNTANT);
    $this->inCompany($this->company, fn () => $this->project->forceFill(['state_code' => '27'])->save());
    $this->gst18 = $this->inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST 18%')->value('id'));
});

function openInventoryReport($test, string $key, array $query = [], $user = null)
{
    $user ??= $test->director;

    return $test->actingInCompany($user, $test->company)
        ->get(route('reports.project', [$test->project, $key]).($query === [] ? '' : '?'.http_build_query($query)));
}

test('committed purchase value is the open taxable amount and drops as goods are accepted', function () {
    $po = $this->approvedPo();
    $lines = $this->inCompany($this->company, fn () => $po->items()->orderBy('id')->get());
    expect($lines[0]->taxable_amount)->toBe('40000.00')
        ->and($lines[1]->taxable_amount)->toBe('310000.00');

    openInventoryReport($this, 'budget-vs-actual')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.committed', '350000.00')->where('result.totals.actual', '0.00'));

    $this->approveGrn($this->makeGrn($po, [['60', '10', 'Torn bags'], ['5']]));

    // Cement 50 of 100 still open → 20,000. Steel is fully accepted → 0. Receipt is stock, not project cost.
    openInventoryReport($this, 'budget-vs-actual')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.committed', '20000.00')->where('result.totals.actual', '0.00'));

    $this->approveGrn($this->makeGrn($po->fresh(), [['50']]));

    openInventoryReport($this, 'budget-vs-actual')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.totals.committed', '0.00')->where('result.totals.actual', '0.00'));
});

test('stock quantity matches the ledger and valuation is absent without inventory.view_valuation', function () {
    $po = $this->approvedPo();
    $this->approveGrn($this->makeGrn($po, [['60', '10', 'Torn bags'], ['5']]));

    openInventoryReport($this, 'stock-summary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.rows', function ($rows) {
            $byCode = collect($rows)->keyBy(fn ($row) => strtok($row['material'], ' '));
            $cement = $byCode['CEM53'];
            $steel = $byCode['TMT12'];

            return $cement['qty'] === '50.0000'
                && $cement['cache_qty'] === '50.0000'
                && $cement['flag'] !== 'Cache differs from ledger'
                && $cement['value'] === Decimal::of($cement['avg_cost'])->times('50')->toMoney()
                && $steel['qty'] === '5.0000'
                && $steel['cache_qty'] === '5.0000'
                && $steel['flag'] !== 'Cache differs from ledger';
        }));

    openInventoryReport($this, 'stock-summary', [], $this->storekeeper)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.rows', function ($rows) {
            return collect($rows)->every(fn ($row) => ! array_key_exists('value', $row) && ! array_key_exists('avg_cost', $row) && isset($row['qty']));
        }));
});

test('vendor payables are the approved net less allocations, including a cancelled payment', function () {
    $bill = $this->inCompany($this->company, function () {
        $bill = app(VendorBillService::class)->create($this->project, [
            'bill_type' => 'direct',
            'vendor_id' => $this->vendorThird->id,
            'vendor_invoice_no' => 'PS-100',
            'vendor_invoice_date' => now()->toDateString(),
            'cost_head' => 'equipment',
            'tds_percent' => '0',
            'items' => [[
                'description' => 'Scaffolding hire',
                'unit_id' => $this->unitId('Day'),
                'quantity' => '1',
                'rate' => '100000',
            ]],
        ]);
        app(VendorBillService::class)->submit($bill, $this->accountant);
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($bill->fresh()->pendingApprovalRequest(), $this->pm), $this->director);

        return $bill->fresh();
    });
    expect($bill->net_payable)->toBe('100000.00');

    $payables = fn () => openInventoryReport($this, 'payables', ['view' => 'vendor'])->assertOk();
    $payables()->assertInertia(fn (Assert $page) => $page
        ->where('result.totals.due', '100000.00')
        ->where('result.totals.settled', '0.00')
        ->where('result.totals.outstanding', '100000.00'));

    $pay = function (string $amount) use ($bill) {
        $this->actingInCompany($this->accountant, $this->company)->post(route('projects.payments.store', $this->project), [
            'party_type' => 'vendor', 'party_id' => $this->vendorThird->id, 'amount' => $amount,
            'payment_date' => now()->toDateString(), 'mode' => 'cheque', 'bank_reference' => 'CHQ-'.$amount,
            'allocations' => [['payable_id' => $bill->id, 'amount' => $amount]],
        ])->assertSessionHasNoErrors();

        return $this->inCompany($this->company, fn () => Payment::query()->latest('id')->first());
    };

    $first = $pay('40000');
    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $first]))->assertSessionHasNoErrors();
    $payables()->assertInertia(fn (Assert $page) => $page->where('result.totals.settled', '40000.00')->where('result.totals.outstanding', '60000.00'));

    $second = $pay('60000');
    $this->actingInCompany($this->director, $this->company)->post(route('projects.payments.approve', [$this->project, $second]))->assertSessionHasNoErrors();
    $payables()->assertInertia(fn (Assert $page) => $page->where('result.totals.outstanding', '0.00')->where('result.totals.settled', '100000.00'));

    $this->inCompany($this->company, fn () => app(PaymentService::class)->cancel($first->fresh(), $this->director, 'Cheque bounced'));
    $payables()->assertInertia(fn (Assert $page) => $page->where('result.totals.settled', '60000.00')->where('result.totals.outstanding', '40000.00'));
    expect($bill->fresh())->toBeInstanceOf(VendorBill::class);
});

test('material consumption reports the issue against diary usage and changes nothing', function () {
    $this->stockIn($this->cement, '20', '100');
    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.material-issues.store', $this->project), [
            'warehouse_id' => $this->siteStore()->id,
            'issue_date' => now()->toDateString(),
            'issued_to_user_id' => $this->engineer->id,
            'purpose' => 'Raft',
            'items' => [['material_id' => $this->cement->id, 'quantity' => '5', 'boq_item_id' => $this->boqItemId(), 'task_id' => $this->task->id]],
        ])->assertSessionHasNoErrors();
    $issue = $this->inCompany($this->company, fn () => MaterialIssue::query()->latest('id')->first());
    $this->actingInCompany($this->storekeeper, $this->company)->post(route('projects.material-issues.submit', [$this->project, $issue]))->assertSessionHasNoErrors();
    $request = $this->inCompany($this->company, fn () => $issue->fresh()->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();

    $before = [
        'stock' => $this->inCompany($this->company, fn () => StockTransaction::query()->count()),
        'cost' => $this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()),
        'diaries' => $this->inCompany($this->company, fn () => SiteDiary::query()->count()),
    ];

    openInventoryReport($this, 'material-consumption')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.rows', function ($rows) {
            $row = collect($rows)->first(fn ($row) => str_starts_with($row['material'], 'CEM53'));

            return $row && $row['issued'] === '5.0000' && $row['diary'] === '0.0000' && $row['variance'] === '5.0000';
        }));

    expect($this->inCompany($this->company, fn () => StockTransaction::query()->count()))->toBe($before['stock'])
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()))->toBe($before['cost'])
        ->and($this->inCompany($this->company, fn () => SiteDiary::query()->count()))->toBe($before['diaries']);
});

test('a stock export keeps quantities numeric and hides valuation from the storekeeper', function () {
    ini_set('memory_limit', '512M');
    $po = $this->approvedPo();
    $this->approveGrn($this->makeGrn($po, [['60', '10', 'Torn bags'], ['5']]));

    $load = function ($response) {
        $path = tempnam(sys_get_temp_dir(), 'p9xlsx');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    };

    $xlsx = $this->actingInCompany($this->director, $this->company)
        ->get(route('reports.project-export', [$this->project, 'stock-summary']).'?format=xlsx');
    $xlsx->assertOk();
    $sheet = $load($xlsx);
    $flat = $sheet->toArray();
    $heading = collect($flat)->first(fn ($row) => in_array('Quantity (ledger)', $row, true));
    $qtyColumn = array_search('Quantity (ledger)', $heading, true);
    $sample = collect($flat)->first(fn ($row) => ($row[$qtyColumn] ?? null) == 50);
    expect($flat[0][0])->toBe($this->company->legal_name ?: $this->company->name)
        ->and($flat[1][0])->toBe('Stock Summary')
        ->and($heading)->not->toBeNull()
        ->and(is_numeric($sample[$qtyColumn]))->toBeTrue()
        ->and(in_array('Value', $heading, true))->toBeTrue();

    $pdf = $this->actingInCompany($this->director, $this->company)
        ->get(route('reports.project-export', [$this->project, 'stock-summary']).'?format=pdf');
    $pdf->assertOk();
    $pdfText = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf->getContent(), $streams)) {
        foreach ($streams[1] as $stream) {
            $inflated = (string) @gzuncompress($stream);
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $inflated, $literals)) {
                foreach ($literals[0] as $literal) {
                    $bytes = substr($literal, 1, -1);
                    $pdfText .= (str_contains($bytes, "\x00") ? mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE') : $bytes).' ';
                }
            }
        }
    }
    expect($pdf->headers->get('content-type'))->toContain('pdf')
        ->and($pdfText)->toContain('Stock Summary')
        ->and($pdfText)->toContain($this->company->legal_name ?: $this->company->name);

    $this->inCompany($this->company, function () {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->storekeeper->givePermissionTo('reports.export');
    });
    $restricted = $load($this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('reports.project-export', [$this->project, 'stock-summary']).'?format=xlsx')->assertOk());
    $restrictedHeading = collect($restricted->toArray())->first(fn ($row) => in_array('Quantity (ledger)', $row, true));
    expect(in_array('Value', $restrictedHeading, true))->toBeFalse()
        ->and(in_array('Avg cost', $restrictedHeading, true))->toBeFalse();
});
