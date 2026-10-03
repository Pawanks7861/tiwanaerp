<?php

use App\Enums\Inventory\StockTxnType;
use App\Events\Procurement\GrnApproved;
use App\Listeners\PostGrnStock;
use App\Models\Inventory\StockTransaction;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\PurchaseOrderItem;
use App\Services\Inventory\GrnStockPoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->po = $this->approvedPo();
});

function grnTransactions($test): Collection
{
    return $test->inCompany($test->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::GrnIn)->orderBy('id')->get());
}

test('approving a GRN posts the accepted quantities into its warehouse at the PO rate', function () {
    $grn = $this->approveGrn($this->makeGrn($this->po, [['60', '5', 'Torn bags'], ['2']]));

    $rows = grnTransactions($this);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('qty_in')->all())->toBe(['55.0000', '2.0000'])
        ->and($rows->pluck('unit_cost')->all())->toBe(['400.0000', '62000.0000'])
        ->and($rows->pluck('value')->all())->toBe(['22000.00', '124000.00'])
        ->and($rows->pluck('warehouse_id')->unique()->all())->toBe([$this->siteStore()->id])
        ->and($rows[0]->project_id)->toBe($this->project->id)
        ->and($rows[0]->source_type)->toBe('grn_item')
        ->and($rows[0]->remarks)->toBe($grn->grn_number);

    expect($this->balanceOf($this->cement))->toBe(['quantity' => '55.0000', 'avg_cost' => '400.0000', 'value' => '22000.00'])
        ->and($this->balanceOf($this->steel))->toBe(['quantity' => '2.0000', 'avg_cost' => '62000.0000', 'value' => '124000.00']);
});

test('a retried GrnApproved event or a second posting run never double-posts', function () {
    $grn = $this->approveGrn($this->makeGrn($this->po, [['10']]));

    app(PostGrnStock::class)->handle(new GrnApproved($grn));
    app(PostGrnStock::class)->handle(new GrnApproved($grn));
    $results = $this->inCompany($this->company, fn () => app(GrnStockPoster::class)->post($grn->fresh()));

    expect(grnTransactions($this))->toHaveCount(1)
        ->and($results[0]['created'])->toBeFalse()
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('10.0000');
});

test('the unit cost is the PO rate net of its line discount', function () {
    $grn = $this->makeGrn($this->po, [['10']]);
    $this->inCompany($this->company, fn () => PurchaseOrderItem::query()->whereKey(GrnItem::query()->where('grn_id', $grn->id)->value('purchase_order_item_id'))
        ->update(['discount_percent' => '2.5']));

    $this->approveGrn($grn);

    // 400 − 2.5 % = 390
    expect(grnTransactions($this)[0]->unit_cost)->toBe('390.0000')
        ->and($this->balanceOf($this->cement)['value'])->toBe('3900.00');
});

test('a GRN cannot be submitted without a receiving warehouse', function () {
    $grn = $this->makeGrn($this->po, [['10']]);
    DB::table('grns')->where('id', $grn->id)->update(['warehouse_id' => null]);

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.grns.submit', [$this->project, $grn]))
        ->assertSessionHasErrors('warehouse_id');
});

test('inventory:post-existing-grns backfills approved GRNs once, with a dry run and an explicit warehouse for legacy GRNs', function () {
    Event::fake([GrnApproved::class]);
    $legacy = $this->approveGrn($this->makeGrn($this->po, [['40'], ['1.5']]));
    DB::table('grns')->where('id', $legacy->id)->update(['warehouse_id' => null]);
    $withStore = $this->approveGrn($this->makeGrn($this->po, [['20']]));
    expect(grnTransactions($this))->toHaveCount(0);

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id])
        ->expectsOutputToContain('2 approved GRN(s) not posted to stock')
        ->assertExitCode(0);

    $this->artisan('inventory:post-existing-grns', ['--company' => $this->company->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run: 1 line(s) would be posted')
        ->expectsOutputToContain("{$legacy->grn_number}: no warehouse on the GRN")
        ->assertExitCode(0);
    expect(grnTransactions($this))->toHaveCount(0);

    $this->artisan('inventory:post-existing-grns', ['--company' => $this->company->id])
        ->expectsOutputToContain('Posted 1 line(s).')
        ->assertExitCode(0);

    $this->artisan('inventory:post-existing-grns', ['--warehouse' => $this->central->id])
        ->expectsOutputToContain('Posted 2 line(s).')
        ->assertExitCode(0);

    $this->artisan('inventory:post-existing-grns')->expectsOutputToContain('Posted 0 line(s).')->assertExitCode(0);

    expect(grnTransactions($this))->toHaveCount(3)
        ->and($this->balanceOf($this->cement, $this->central))->toBe(['quantity' => '40.0000', 'avg_cost' => '400.0000', 'value' => '16000.00'])
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '20.0000', 'avg_cost' => '400.0000', 'value' => '8000.00'])
        ->and(DB::table('grns')->where('id', $legacy->id)->value('warehouse_id'))->toBeNull()
        ->and(grnTransactions($this)->firstWhere('warehouse_id', $this->central->id)->remarks)->toContain('backfill');

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id])
        ->expectsOutputToContain('0 drifted balance(s), 0 approved GRN(s) not posted')
        ->assertExitCode(0);
});

test('the backfill dry run projects the running balance across GRNs of the same store and material', function () {
    Event::fake([GrnApproved::class]);
    $this->approveGrn($this->makeGrn($this->po, [['10']]));
    $this->approveGrn($this->makeGrn($this->po, [['20']]));

    $this->artisan('inventory:post-existing-grns', ['--company' => $this->company->id, '--dry-run' => true])
        ->expectsOutputToContain('10.0000 / 4000.00')
        ->expectsOutputToContain('30.0000 / 12000.00')
        ->expectsOutputToContain('Dry run: 2 line(s) would be posted')
        ->assertExitCode(0);
    expect(grnTransactions($this))->toHaveCount(0);
});

test('the backfill refuses a warehouse of another project for a legacy GRN', function () {
    Event::fake([GrnApproved::class]);
    $legacy = $this->approveGrn($this->makeGrn($this->po, [['40']]));
    DB::table('grns')->where('id', $legacy->id)->update(['warehouse_id' => null]);

    $this->artisan('inventory:post-existing-grns', ['--warehouse' => $this->otherStore->id])
        ->expectsOutputToContain('belongs to another project')
        ->assertExitCode(0);

    expect(grnTransactions($this))->toHaveCount(0);
});
