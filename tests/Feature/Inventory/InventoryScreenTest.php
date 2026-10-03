<?php

use App\Enums\Inventory\StockTxnType;
use App\Models\Inventory\LowStockAlert;
use App\Notifications\Inventory\InventoryNotification;
use App\Services\Inventory\LowStockScanner;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockMovement;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->cement, '10', '120');
    $this->stockIn($this->steel, '4', '60000', $this->central);
    $this->stockIn($this->cement, '7', '90', $this->otherStore, $this->otherProject);
});

test('stock overview hides valuation from users without inventory.view_valuation', function () {
    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.inventory.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Stock/Index')
            ->has('rows.data', 2)
            ->where('can.view_valuation', false)
            ->missing('rows.data.0.avg_cost')
            ->missing('rows.data.0.value')
            ->missing('warehouses.0.value'));

    $this->actingInCompany($this->director, $this->company)
        ->get(route('projects.inventory.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('can.view_valuation', true)
            ->where('rows.data.0.name', 'Cement OPC 53')
            ->where('rows.data.0.quantity', '20.0000')
            ->where('rows.data.0.avg_cost', '110.0000')
            ->where('rows.data.0.value', '2200.00')
            ->where('rows.data.1.central', true));
});

test('another project\'s store is not reachable from this project', function () {
    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.inventory.index', [$this->project, 'warehouse' => $this->otherStore->id]))
        ->assertSessionHasErrors('warehouse');

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.inventory.ledger', $this->project))
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Stock/Ledger')->has('rows.data', 3));
});

test('ledger shows a running balance for one store and item, and masks other projects\' references', function () {
    $this->stockOut($this->cement, '5');
    $this->stockOut($this->steel, '1', $this->central);   // posted under this project
    $this->inCompany($this->company, fn () => app(StockLedgerService::class)->post(new StockMovement(
        source: $this->fakeSource(), type: StockTxnType::IssueOut, warehouse: $this->central,
        materialId: $this->steel->id, quantity: Decimal::of('1'), date: now()->toDateString(),
        projectId: $this->otherProject->id, remarks: 'Tower B secret',
    )));

    $this->actingInCompany($this->director, $this->company)
        ->get(route('projects.inventory.ledger', [$this->project, 'warehouse' => $this->siteStore()->id, 'material' => $this->cement->id]))
        ->assertInertia(fn (Assert $page) => $page->where('running', true)
            ->has('rows.data', 3)
            ->where('rows.data.0.running_qty', '15.0000')
            ->where('rows.data.0.running_value', '1650.00')
            ->where('rows.data.2.running_qty', '10.0000'));

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.inventory.ledger', [$this->project, 'warehouse' => $this->central->id]))
        ->assertInertia(fn (Assert $page) => $page->where('running', false)
            ->where('rows.data.0.other_project', true)
            ->where('rows.data.0.remarks', null)
            ->where('rows.data.0.reference', null)
            ->missing('rows.data.0.value'));
});

test('inventory screens need inventory.view and project access', function () {
    $outsider = $this->createMember($this->company, DefaultRoles::STORE_MANAGER);
    $billing = $this->createMember($this->company, DefaultRoles::BILLING_ENGINEER);
    $otherCompany = $this->createCompany();
    $stranger = $this->createMember($otherCompany, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($outsider, $this->company)->get(route('projects.inventory.index', $this->project))->assertForbidden();
    $this->actingInCompany($billing, $this->company)->get(route('projects.inventory.ledger', $this->project))->assertForbidden();
    $this->actingInCompany($stranger, $otherCompany)->get(route('projects.inventory.index', $this->project))->assertNotFound();
});

test('low stock scan alerts once, clears on recovery and alerts again on a later dip', function () {
    Notification::fake();
    $this->inCompany($this->company, fn () => $this->cement->forceFill(['reorder_level' => '25'])->save());
    $scanner = app(LowStockScanner::class);

    // Cement is low in this project's store (20) and in Tower B's store (7).
    expect($scanner->scan($this->company))->toBe(['low' => 2, 'alerted' => 2, 'cleared' => 0]);
    expect($scanner->scan($this->company))->toBe(['low' => 2, 'alerted' => 0, 'cleared' => 0]);
    Notification::assertSentToTimes($this->storekeeper, InventoryNotification::class, 1);
    Notification::assertSentTo($this->storekeeper, InventoryNotification::class, fn (InventoryNotification $n) => $n->kind === 'inventory.low_stock'
        && str_contains($n->body, 'Cement OPC 53 (CEM53) in Tower A site store is at 20.0000')
        && $n->url === route('projects.inventory.index', ['project' => $this->project->id, 'warehouse' => $this->siteStore()->id, 'low' => 1]));
    Notification::assertNotSentTo($this->engineer, InventoryNotification::class);

    $this->stockIn($this->cement, '10', '110');   // 30 > 25
    expect($scanner->scan($this->company))->toBe(['low' => 1, 'alerted' => 0, 'cleared' => 1])
        ->and($this->inCompany($this->company, fn () => LowStockAlert::query()->count()))->toBe(1);

    $this->stockOut($this->cement, '6');
    expect($scanner->scan($this->company))->toBe(['low' => 2, 'alerted' => 1, 'cleared' => 0]);

    $this->artisan('inventory:scan-low-stock', ['--company' => $this->company->id])->assertSuccessful();
});
