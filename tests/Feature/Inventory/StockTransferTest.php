<?php

use App\Enums\Inventory\StockTransferStatus;
use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockTransaction;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferReceipt;
use App\Services\Inventory\StockTransferService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->stockIn($this->cement, '10', '100', $this->central);   // 10 @ 100 in the central store
    $this->stockIn($this->cement, '20', '115', $this->central);   // 30 @ 110
});

function transferOf($test, int $id): StockTransfer
{
    return $test->inCompany($test->company, fn () => StockTransfer::query()->with('items')->findOrFail($id));
}

function dispatchedTransfer($test, string $qty = '9'): StockTransfer
{
    return $test->inCompany($test->company, function () use ($test, $qty) {
        $transfer = app(StockTransferService::class)->create($test->project, [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $test->central->id,
            'to_warehouse_id' => $test->siteStore()->id,
            'items' => [['material_id' => $test->cement->id, 'quantity' => $qty]],
        ]);
        app(StockTransferService::class)->dispatch($transfer, $test->storekeeper);

        return $transfer->fresh();
    });
}

function receivePayload($test, StockTransfer $transfer, string $qty, ?string $key = null): array
{
    return [
        'idempotency_key' => $key ?? (string) str()->uuid(),
        'receipt_date' => now()->toDateString(),
        'items' => [['stock_transfer_item_id' => $transfer->items()->value('id'), 'quantity' => $qty]],
    ];
}

test('dispatch moves stock into transit and receipts split the value exactly', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.stock-transfers.store', $this->project), [
        'transfer_date' => now()->toDateString(),
        'from_warehouse_id' => $this->central->id,
        'to_warehouse_id' => $this->siteStore()->id,
        'vehicle_no' => 'MH12AB1234',
        'items' => [['material_id' => $this->cement->id, 'quantity' => '3']],
    ])->assertSessionHasNoErrors();
    $transfer = $this->inCompany($this->company, fn () => StockTransfer::query()->sole());
    expect($transfer->transfer_number)->toBe('STR-PRJ001-0001')->and($transfer->status)->toBe(StockTransferStatus::Draft);

    $store->post(route('projects.stock-transfers.dispatch', [$this->project, $transfer]))->assertSessionHasNoErrors();
    $transfer = transferOf($this, $transfer->id);
    expect($transfer->status)->toBe(StockTransferStatus::Dispatched)
        ->and($transfer->items[0]->unit_cost)->toBe('110.0000')
        ->and($transfer->items[0]->value)->toBe('330.00')
        ->and($this->balanceOf($this->cement, $this->central))->toBe(['quantity' => '27.0000', 'avg_cost' => '110.0000', 'value' => '2970.00'])
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('0.0000');

    // Retrying dispatch changes nothing.
    $this->inCompany($this->company, fn () => app(StockTransferService::class)->dispatch($transfer, $this->storekeeper));
    expect($this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::TransferOut)->count()))->toBe(1);

    // 1 of 3 received, then the remaining 2: the receipts carry 110.00 + 220.00 = 330.00.
    $store->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), receivePayload($this, $transfer, '1'))->assertSessionHasNoErrors();
    expect(transferOf($this, $transfer->id)->status)->toBe(StockTransferStatus::PartiallyReceived);
    $store->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), receivePayload($this, $transfer, '2.5'))->assertSessionHasErrors('items.0.quantity');
    $store->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), receivePayload($this, $transfer, '2'))->assertSessionHasNoErrors();

    $transfer = transferOf($this, $transfer->id);
    expect($transfer->status)->toBe(StockTransferStatus::Received)
        ->and($transfer->items[0]->received_qty)->toBe('3.0000')
        ->and($transfer->items[0]->received_value)->toBe('330.00')
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '3.0000', 'avg_cost' => '110.0000', 'value' => '330.00']);
});

test('odd unit costs: the last receipt takes the remaining value so nothing is lost in rounding', function () {
    $this->stockIn($this->steel, '3', '100.01', $this->central);   // 300.03
    $transfer = $this->inCompany($this->company, function () {
        $t = app(StockTransferService::class)->create($this->project, [
            'transfer_date' => now()->toDateString(), 'from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->siteStore()->id,
            'items' => [['material_id' => $this->steel->id, 'quantity' => '3']],
        ]);
        app(StockTransferService::class)->dispatch($t, $this->storekeeper);

        return $t->fresh();
    });

    foreach (['0.3333', '1.3333', '1.3334'] as $qty) {
        $this->inCompany($this->company, fn () => app(StockTransferService::class)->receive($transfer->fresh(), $this->storekeeper, receivePayload($this, $transfer, $qty)));
    }

    expect($this->balanceOf($this->steel))->toBe(['quantity' => '3.0000', 'avg_cost' => '100.0100', 'value' => '300.03'])
        ->and($this->balanceOf($this->steel, $this->central))->toBe(['quantity' => '0.0000', 'avg_cost' => '0.0000', 'value' => '0.00']);
});

test('a retried receipt with the same key is recorded once, even after the transfer completed', function () {
    $transfer = dispatchedTransfer($this, '4');
    $payload = receivePayload($this, $transfer, '4', 'fixed-key-123');
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), $payload)->assertSessionHasNoErrors();
    $store->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), $payload)
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'This receipt was already recorded.');

    expect($this->inCompany($this->company, fn () => StockTransferReceipt::query()->count()))->toBe(1)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('4.0000');
});

test('dispatch needs stock in the source store', function () {
    $this->actingInCompany($this->storekeeper, $this->company)->post(route('projects.stock-transfers.store', $this->project), [
        'transfer_date' => now()->toDateString(), 'from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->siteStore()->id,
        'items' => [['material_id' => $this->cement->id, 'quantity' => '31']],
    ])->assertSessionHasNoErrors();
    $transfer = $this->inCompany($this->company, fn () => StockTransfer::query()->sole());

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.stock-transfers.dispatch', [$this->project, $transfer]))
        ->assertSessionHasErrors('items');
    expect(transferOf($this, $transfer->id)->status)->toBe(StockTransferStatus::Draft)
        ->and($this->balanceOf($this->cement, $this->central)['quantity'])->toBe('30.0000');
});

test('stores must differ and be reachable from the project', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $base = ['transfer_date' => now()->toDateString(), 'items' => [['material_id' => $this->cement->id, 'quantity' => '1']]];

    $store->post(route('projects.stock-transfers.store', $this->project), $base + ['from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->central->id])
        ->assertSessionHasErrors('to_warehouse_id');
    $store->post(route('projects.stock-transfers.store', $this->project), $base + ['from_warehouse_id' => $this->central->id, 'to_warehouse_id' => $this->otherStore->id])
        ->assertSessionHasErrors('to_warehouse_id');
    $store->post(route('projects.stock-transfers.store', $this->project), $base + ['from_warehouse_id' => $this->otherStore->id, 'to_warehouse_id' => $this->siteStore()->id])
        ->assertSessionHasErrors('from_warehouse_id');
});

test('cancel before receipt puts the goods back; close short returns the undelivered rest', function () {
    $first = dispatchedTransfer($this, '6');
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.stock-transfers.cancel', [$this->project, $first]), ['reason' => 'Truck unavailable'])->assertSessionHasNoErrors();

    expect(transferOf($this, $first->id)->status)->toBe(StockTransferStatus::Cancelled)
        ->and($this->balanceOf($this->cement, $this->central))->toBe(['quantity' => '30.0000', 'avg_cost' => '110.0000', 'value' => '3300.00']);

    $second = dispatchedTransfer($this, '9');
    $store->post(route('projects.stock-transfers.receive', [$this->project, $second]), receivePayload($this, $second, '4'))->assertSessionHasNoErrors();
    $store->post(route('projects.stock-transfers.cancel', [$this->project, $second]), ['reason' => 'Truck unavailable'])->assertForbidden();
    $store->post(route('projects.stock-transfers.close-short', [$this->project, $second]), ['reason' => 'Five bags damaged in transit'])->assertSessionHasNoErrors();

    $second = transferOf($this, $second->id);
    expect($second->status)->toBe(StockTransferStatus::ClosedShort)
        ->and($second->items[0]->short_closed_qty)->toBe('5.0000')
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '4.0000', 'avg_cost' => '110.0000', 'value' => '440.00'])
        ->and($this->balanceOf($this->cement, $this->central))->toBe(['quantity' => '26.0000', 'avg_cost' => '110.0000', 'value' => '2860.00']);

    // Receiving after close is refused.
    expect(fn () => $this->inCompany($this->company, fn () => app(StockTransferService::class)->receive($second, $this->storekeeper, receivePayload($this, $second, '1'))))
        ->toThrow(ValidationException::class, 'Only a dispatched transfer');
});

test('transfer permissions', function () {
    $transfer = dispatchedTransfer($this, '2');

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.stock-transfers.receive', [$this->project, $transfer]), receivePayload($this, $transfer, '1'))
        ->assertForbidden();
    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.stock-transfers.show', [$this->otherProject, $transfer]))
        ->assertNotFound();
});
