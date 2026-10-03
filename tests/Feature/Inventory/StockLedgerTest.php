<?php

use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransaction;
use App\Models\Masters\Warehouse;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockMovement;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
});

test('weighted average: 10 @ 100 + 10 @ 120 = 20 @ 110; issuing 5 leaves 15 @ 110 worth 1,650', function () {
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->cement, '10', '120');
    expect($this->balanceOf($this->cement))->toBe(['quantity' => '20.0000', 'avg_cost' => '110.0000', 'value' => '2200.00']);

    $out = $this->stockOut($this->cement, '5');
    expect($out->unit_cost)->toBe('110.0000')
        ->and($out->value)->toBe('550.00')
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '15.0000', 'avg_cost' => '110.0000', 'value' => '1650.00']);

    // (1,650 + 5 × 130) / 20 = 115
    $this->stockIn($this->cement, '5', '130');
    expect($this->balanceOf($this->cement))->toBe(['quantity' => '20.0000', 'avg_cost' => '115.0000', 'value' => '2300.00']);
});

test('issuing the whole balance takes the whole value so an empty store is worth exactly zero', function () {
    $this->stockIn($this->cement, '3', '3.3333');   // value 10.00, average 3.3333
    $this->stockOut($this->cement, '1');              // 3.33
    expect($this->balanceOf($this->cement))->toBe(['quantity' => '2.0000', 'avg_cost' => '3.3350', 'value' => '6.67']);

    $last = $this->stockOut($this->cement, '2');
    expect($last->value)->toBe('6.67')
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '0.0000', 'avg_cost' => '0.0000', 'value' => '0.00']);

    // Restocking after zero starts a fresh average.
    $this->stockIn($this->cement, '4', '250');
    expect($this->balanceOf($this->cement))->toBe(['quantity' => '4.0000', 'avg_cost' => '250.0000', 'value' => '1000.00']);
});

test('stock can never go negative and a rejected movement writes nothing', function () {
    $this->stockIn($this->cement, '10', '100');

    expect(fn () => $this->stockOut($this->cement, '10.0001'))->toThrow(ValidationException::class, 'Insufficient stock');
    expect($this->inCompany($this->company, fn () => StockTransaction::query()->count()))->toBe(1)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('10.0000');

    // Other warehouses are separate balances.
    expect(fn () => $this->stockOut($this->cement, '1', $this->central))->toThrow(ValidationException::class);
});

test('posting the same source line twice is idempotent', function () {
    $source = $this->fakeSource();
    $post = fn () => $this->inCompany($this->company, fn () => app(StockLedgerService::class)->post(new StockMovement(
        source: $source, type: StockTxnType::GrnIn, warehouse: $this->siteStore(), materialId: $this->cement->id,
        quantity: Decimal::of('7'), date: now()->toDateString(), projectId: $this->project->id, unitCost: Decimal::of('100'),
    )));

    $first = $post();
    $second = $post();

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->count()))->toBe(1)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('7.0000');
});

test('a reversal is a compensating row; it is idempotent and cannot itself be reversed', function () {
    $this->stockIn($this->cement, '10', '100');
    $out = $this->stockOut($this->cement, '4');
    $ledger = app(StockLedgerService::class);

    $reversal = $this->inCompany($this->company, fn () => $ledger->reverse($out, 'Wrong issue'));
    $again = $this->inCompany($this->company, fn () => $ledger->reverse($out, 'Wrong issue'));

    expect($reversal->txn_type)->toBe(StockTxnType::Reversal)
        ->and($reversal->reverses_id)->toBe($out->id)
        ->and($reversal->qty_in)->toBe('4.0000')
        ->and($reversal->value)->toBe('400.00')
        ->and($again->id)->toBe($reversal->id)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '10.0000', 'avg_cost' => '100.0000', 'value' => '1000.00']);

    expect(fn () => $this->inCompany($this->company, fn () => $ledger->reverse($reversal)))->toThrow(LogicException::class);
});

test('a receipt whose stock was already consumed cannot be reversed', function () {
    $in = $this->stockIn($this->cement, '10', '100');
    $this->stockOut($this->cement, '6');

    expect(fn () => $this->inCompany($this->company, fn () => app(StockLedgerService::class)->reverse($in)))
        ->toThrow(ValidationException::class, 'stock has since been issued');
    expect($this->balanceOf($this->cement)['quantity'])->toBe('4.0000');
});

test('ledger rows are append-only', function () {
    $txn = $this->stockIn($this->cement, '10', '100');

    expect(fn () => $this->inCompany($this->company, fn () => $txn->forceFill(['qty_in' => '99'])->save()))->toThrow(LogicException::class)
        ->and(fn () => $this->inCompany($this->company, fn () => $txn->delete()))->toThrow(LogicException::class);
});

test('the ledger refuses a warehouse of another company', function () {
    $other = $this->createCompany();
    $foreign = $this->inCompany($other, fn () => Warehouse::query()->create(['code' => 'X-1', 'name' => 'Foreign', 'type' => 'central']));

    expect(fn () => $this->stockIn($this->cement, '1', '1', $foreign))->toThrow(LogicException::class, 'different company');
});

test('reconcile reports no drift on a clean ledger, detects tampering and repairs only the cache', function () {
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->steel, '2', '61250');
    $this->stockOut($this->cement, '3');

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id])
        ->expectsOutputToContain('0 drifted balance(s)')
        ->assertExitCode(0);

    DB::table('stock_balances')->where('warehouse_id', $this->siteStore()->id)->where('material_id', $this->cement->id)->update(['quantity' => '999', 'value' => '1']);
    $rows = $this->inCompany($this->company, fn () => StockTransaction::query()->count());

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id])
        ->expectsOutputToContain('1 drifted balance(s)')
        ->assertExitCode(1);

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id, '--fix' => true, '--force' => true])
        ->expectsOutputToContain('Repaired 1 cached balance(s)')
        ->assertExitCode(0);

    expect($this->balanceOf($this->cement))->toBe(['quantity' => '7.0000', 'avg_cost' => '100.0000', 'value' => '700.00'])
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->count()))->toBe($rows);

    $this->artisan('inventory:reconcile', ['--company' => $this->company->id])->assertExitCode(0);
});

test('reconcile filters by warehouse and material and stays inside the named company', function () {
    $this->stockIn($this->cement, '5', '10');
    $other = $this->createCompany();
    DB::table('stock_balances')->update(['quantity' => '1']);

    $this->artisan('inventory:reconcile', ['--company' => $other->id])->expectsOutputToContain('0 drifted')->assertExitCode(0);
    $this->artisan('inventory:reconcile', ['--company' => $this->company->id, '--material' => $this->steel->id])->assertExitCode(0);
    $this->artisan('inventory:reconcile', ['--company' => $this->company->id, '--warehouse' => $this->central->id])->assertExitCode(0);
    $this->artisan('inventory:reconcile', ['--company' => $this->company->id, '--material' => $this->cement->id])->assertExitCode(1);

    expect($this->inCompany($this->company, fn () => StockBalance::query()->count()))->toBe(1);
});
