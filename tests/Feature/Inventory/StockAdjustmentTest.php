<?php

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransaction;
use App\Services\Inventory\StockAdjustmentService;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
});

function adjustmentOf($test, ?int $id = null): StockAdjustment
{
    return $test->inCompany($test->company, fn () => $id
        ? StockAdjustment::query()->with('items')->findOrFail($id)
        : StockAdjustment::query()->with('items')->latest('id')->firstOrFail());
}

function adjustmentPayload($test, string $reason, array $items): array
{
    return [
        'warehouse_id' => $test->siteStore()->id,
        'adjustment_date' => now()->toDateString(),
        'reason' => $reason,
        'remarks' => 'Monthly physical count',
        'items' => $items,
    ];
}

test('opening stock: storekeeper submits, director approves, posted at the entered cost', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'opening', [
        ['material_id' => $this->cement->id, 'physical_qty' => '40'],
    ]))->assertSessionHasErrors('items.0.unit_cost');

    $store->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'opening', [
        ['material_id' => $this->cement->id, 'physical_qty' => '40', 'unit_cost' => '105.5', 'difference' => '999', 'system_qty' => '7'],
    ]))->assertSessionHasNoErrors();

    $adjustment = adjustmentOf($this);
    expect($adjustment->adjustment_number)->toBe('ADJ-PRJ001-0001')
        ->and($adjustment->items[0]->system_qty)->toBe('0.0000')
        ->and($adjustment->items[0]->difference)->toBe('40.0000');

    $store->post(route('projects.stock-adjustments.submit', [$this->project, $adjustment]))->assertSessionHasNoErrors();
    $store->post(route('projects.stock-adjustments.approve', [$this->project, $adjustment]))->assertForbidden();

    $director = $this->actingInCompany($this->director, $this->company);
    $director->post(route('projects.stock-adjustments.approve', [$this->project, $adjustment]))->assertSessionHasNoErrors();
    $director->post(route('projects.stock-adjustments.approve', [$this->project, $adjustment]))->assertSessionHasNoErrors();

    expect(adjustmentOf($this, $adjustment->id)->status)->toBe(InventoryDocumentStatus::Approved)
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::Opening)->count()))->toBe(1)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '40.0000', 'avg_cost' => '105.5000', 'value' => '4220.00']);

    // Opening stock again for an item that has moved is refused.
    $this->actingInCompany($this->storekeeper, $this->company)->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'opening', [
        ['material_id' => $this->cement->id, 'physical_qty' => '50', 'unit_cost' => '100'],
    ]))->assertSessionHasErrors('items.0.material_id');
});

test('count corrections post the server-computed difference at the weighted average', function () {
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->cement, '10', '120');   // 20 @ 110

    $service = app(StockAdjustmentService::class);
    $gain = $this->inCompany($this->company, fn () => $service->create($this->project, adjustmentPayload($this, 'count_correction', [
        ['material_id' => $this->cement->id, 'physical_qty' => '23', 'unit_cost' => '999'],
    ])));
    $this->inCompany($this->company, function () use ($service, $gain) {
        $service->submit($gain, $this->storekeeper);
        $service->approve($gain, $this->director);
    });
    expect($this->balanceOf($this->cement))->toBe(['quantity' => '23.0000', 'avg_cost' => '110.0000', 'value' => '2530.00']);

    $loss = $this->inCompany($this->company, fn () => $service->create($this->project, adjustmentPayload($this, 'damage', [
        ['material_id' => $this->cement->id, 'physical_qty' => '21.5'],
    ])));
    $this->inCompany($this->company, function () use ($service, $loss) {
        $service->submit($loss, $this->storekeeper);
        $service->approve($loss, $this->director);
    });

    $txn = $this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::AdjustmentOut)->sole());
    expect($txn->qty_out)->toBe('1.5000')
        ->and($txn->value)->toBe('165.00')
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '21.5000', 'avg_cost' => '110.0000', 'value' => '2365.00']);
});

test('damage and theft cannot add stock, and a zero difference is refused', function () {
    $this->stockIn($this->cement, '10', '100');
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'damage', [['material_id' => $this->cement->id, 'physical_qty' => '12']]))
        ->assertSessionHasErrors('items.0.physical_qty');
    $store->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'theft', [['material_id' => $this->cement->id, 'physical_qty' => '10']]))
        ->assertSessionHasErrors('items.0.physical_qty');
    $store->post(route('projects.stock-adjustments.store', $this->project), adjustmentPayload($this, 'count_correction', [['material_id' => $this->steel->id, 'physical_qty' => '2']]))
        ->assertSessionHasErrors('items.0.unit_cost');
});

test('the submitter cannot approve, the storekeeper cannot approve, and a stale count is refused', function () {
    $this->stockIn($this->cement, '10', '100');
    $service = app(StockAdjustmentService::class);
    $adjustment = $this->inCompany($this->company, fn () => $service->create($this->project, adjustmentPayload($this, 'count_correction', [
        ['material_id' => $this->cement->id, 'physical_qty' => '8'],
    ])));
    $this->inCompany($this->company, fn () => $service->submit($adjustment, $this->director));

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.stock-adjustments.approve', [$this->project, $adjustment]))->assertForbidden();

    // Stock moves after the count: approval refuses to post.
    $this->stockOut($this->cement, '1');
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.stock-adjustments.approve', [$this->project, $adjustment]))
        ->assertSessionHasErrors(['items' => 'The book stock of Cement OPC 53 changed since it was counted (was 10.0000, now 9.0000). Reject it so the count can be redone.']);
    expect(adjustmentOf($this, $adjustment->id)->status)->toBe(InventoryDocumentStatus::Submitted);

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.stock-adjustments.reject', [$this->project, $adjustment]), ['reason' => 'Recount needed'])->assertSessionHasNoErrors();
    expect(adjustmentOf($this, $adjustment->id)->status)->toBe(InventoryDocumentStatus::Rejected);
});

test('cancelling a posted adjustment reverses it', function () {
    $this->stockIn($this->cement, '10', '100');
    $service = app(StockAdjustmentService::class);
    $adjustment = $this->inCompany($this->company, fn () => $service->create($this->project, adjustmentPayload($this, 'theft', [
        ['material_id' => $this->cement->id, 'physical_qty' => '7'],
    ])));
    $this->inCompany($this->company, function () use ($service, $adjustment) {
        $service->submit($adjustment, $this->storekeeper);
        $service->approve($adjustment, $this->director);
    });
    expect($this->balanceOf($this->cement)['quantity'])->toBe('7.0000');

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.stock-adjustments.cancel', [$this->project, $adjustment]), ['reason' => 'Bags found in shed'])->assertSessionHasNoErrors();

    expect(adjustmentOf($this, $adjustment->id)->status)->toBe(InventoryDocumentStatus::Cancelled)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '10.0000', 'avg_cost' => '100.0000', 'value' => '1000.00']);
});
