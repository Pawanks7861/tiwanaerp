<?php

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Enums\Inventory\StockTxnType;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\StockTransaction;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\MaterialReturnService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->cement, '10', '120');   // 20 @ 110
});

function returnOf($test, int $id): MaterialReturn
{
    return $test->inCompany($test->company, fn () => MaterialReturn::query()->with('items')->findOrFail($id));
}

function approveReturn($test, MaterialReturn $return): MaterialReturn
{
    return $test->inCompany($test->company, function () use ($test, $return) {
        app(MaterialReturnService::class)->submit($return->fresh(), $test->storekeeper);
        app(ApprovalService::class)->approve($return->fresh()->pendingApprovalRequest(), $test->pm);

        return $return->fresh();
    });
}

test('a site return comes back at the original issue cost and credits the project cost on the same line', function () {
    $issue = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '6', 'boq_item_id' => $this->boqItemId(), 'task_id' => $this->task->id]]));
    $this->stockIn($this->cement, '14', '200');   // store average moves to (14*110 + 14*200)/28 = 155
    $issueItemId = $this->inCompany($this->company, fn () => $issue->items()->value('id'));

    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.material-returns.store', $this->project), [
        'return_type' => 'site_to_store', 'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id,
        'reason' => 'Excess after raft pour', 'items' => [['material_issue_item_id' => $issueItemId, 'quantity' => '7']],
    ])->assertSessionHasErrors('items.0.quantity');

    $store->post(route('projects.material-returns.store', $this->project), [
        'return_type' => 'site_to_store', 'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id,
        'reason' => 'Excess after raft pour', 'items' => [['material_issue_item_id' => $issueItemId, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();
    $return = $this->inCompany($this->company, fn () => MaterialReturn::query()->sole());
    expect($return->return_number)->toBe('RET-PRJ001-0001');

    $return = approveReturn($this, $return);
    $txn = $this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::ReturnIn)->sole());
    $credit = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->where('source_type', 'material_return_item')->sole());

    expect($return->status)->toBe(InventoryDocumentStatus::Approved)
        ->and($txn->unit_cost)->toBe('110.0000')
        ->and($txn->value)->toBe('220.00')
        ->and($credit->amount)->toBe('-220.00')
        ->and($credit->boq_item_id)->toBe($this->boqItemId())
        ->and($credit->task_id)->toBe($this->task->id)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '30.0000', 'avg_cost' => '152.0000', 'value' => '4560.00']);

    // Only 4 more can come back; returning exactly those takes the remaining issue value.
    $rest = $this->inCompany($this->company, fn () => app(MaterialReturnService::class)->create($this->project, MaterialReturnType::SiteToStore, [
        'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id, 'reason' => 'Rest',
        'items' => [['material_issue_item_id' => $issueItemId, 'quantity' => '4']],
    ]));
    approveReturn($this, $rest);
    expect($this->inCompany($this->company, fn () => ProjectCostEntry::query()->sum('amount')))->toEqual(0);
});

test('a return posted twice changes nothing, and cancelling reverses stock and cost', function () {
    $issue = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '5']]));
    $return = $this->inCompany($this->company, fn () => app(MaterialReturnService::class)->create($this->project, MaterialReturnType::SiteToStore, [
        'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id, 'reason' => 'Excess',
        'items' => [['material_issue_item_id' => $issue->items()->value('id'), 'quantity' => '3']],
    ]));
    $return = approveReturn($this, $return);
    $this->inCompany($this->company, fn () => app(MaterialReturnService::class)->post($return, $this->pm->id));
    expect($this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::ReturnIn)->count()))->toBe(1);

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.material-returns.cancel', [$this->project, $return]), ['reason' => 'Wrong document'])->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.material-returns.cancel', [$this->project, $return]), ['reason' => 'Wrong document'])->assertSessionHasNoErrors();

    expect(returnOf($this, $return->id)->status)->toBe(InventoryDocumentStatus::Cancelled)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '15.0000', 'avg_cost' => '110.0000', 'value' => '1650.00'])
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->sum('amount')))->toEqual(550);
});

test('a vendor return leaves at the weighted average and is limited to the GRN quantity', function () {
    $grn = $this->approveGrn($this->makeGrn($this->approvedPo(), [['50'], ['2']]));   // 50 @ 400 posted to the site store
    $grnItem = $this->inCompany($this->company, fn () => $grn->items()->where('material_id', $this->cement->id)->first());
    $before = $this->balanceOf($this->cement);
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->post(route('projects.material-returns.store', $this->project), [
        'return_type' => 'to_vendor', 'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id,
        'grn_id' => $grn->id, 'reason' => 'Lumpy bags', 'items' => [['grn_item_id' => $grnItem->id, 'quantity' => '51']],
    ])->assertSessionHasErrors('items.0.quantity');
    $store->post(route('projects.material-returns.store', $this->project), [
        'return_type' => 'to_vendor', 'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id,
        'grn_id' => $grn->id, 'vendor_id' => $this->vendorThird->id, 'reason' => 'Lumpy bags', 'items' => [['grn_item_id' => $grnItem->id, 'quantity' => '10']],
    ])->assertSessionHasNoErrors();

    $return = $this->inCompany($this->company, fn () => MaterialReturn::query()->sole());
    expect($return->vendor_id)->toBe($grn->vendor_id);
    approveReturn($this, $return);

    $txn = $this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::ReturnToVendorOut)->sole());
    expect($txn->unit_cost)->toBe($before['avg_cost'])
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('60.0000')
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()))->toBe(0);
});

test('a vendor return cannot take more than the store holds', function () {
    $return = $this->inCompany($this->company, fn () => app(MaterialReturnService::class)->create($this->project, MaterialReturnType::ToVendor, [
        'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id, 'vendor_id' => $this->vendorIntra->id,
        'reason' => 'Rejected', 'items' => [['material_id' => $this->cement->id, 'quantity' => '21']],
    ]));

    expect(fn () => approveReturn($this, $return))->toThrow(ValidationException::class, 'Insufficient stock');
    expect(returnOf($this, $return->id)->status)->toBe(InventoryDocumentStatus::Submitted);
});
