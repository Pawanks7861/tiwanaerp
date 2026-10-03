<?php

use App\Enums\Approval\ApprovalStatus;
use App\Enums\CostHead;
use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\MaterialReturnType;
use App\Enums\Inventory\StockTxnType;
use App\Models\Boq\BoqItem;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\StockTransaction;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\MaterialIssueService;
use App\Services\Inventory\MaterialReturnService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInventoryData;

uses(BuildsInventoryData::class);

beforeEach(function () {
    $this->setUpInventory();
    $this->stockIn($this->cement, '10', '100');
    $this->stockIn($this->cement, '10', '120');
});

function issueOf($test, ?int $id = null): MaterialIssue
{
    return $test->inCompany($test->company, fn () => $id ? MaterialIssue::query()->with('items')->findOrFail($id) : MaterialIssue::query()->with('items')->latest('id')->firstOrFail());
}

function issuePayload($test, array $extra = []): array
{
    return $extra + [
        'warehouse_id' => $test->siteStore()->id,
        'issue_date' => now()->toDateString(),
        'issued_to_user_id' => $test->engineer->id,
        'purpose' => 'Raft concreting',
        'items' => [['material_id' => $test->cement->id, 'quantity' => '5', 'boq_item_id' => $test->boqItemId(), 'task_id' => $test->task->id]],
    ];
}

test('an approved issue posts issue_out at the weighted average and a matching material cost with BOQ line and task', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['status' => 'approved', 'company_id' => 999]))->assertSessionHasNoErrors();

    $issue = issueOf($this);
    expect($issue->issue_number)->toBe('ISS-PRJ001-0001')
        ->and($issue->status)->toBe(InventoryDocumentStatus::Draft)
        ->and($issue->company_id)->toBe($this->company->id)
        ->and($issue->items[0]->unit_cost)->toBeNull();

    $store->post(route('projects.material-issues.submit', [$this->project, $issue]))->assertSessionHasNoErrors();
    $request = $this->inCompany($this->company, fn () => issueOf($this, $issue->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();

    $issue = issueOf($this, $issue->id);
    $line = $issue->items[0];
    $txn = $this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::IssueOut)->sole());
    $cost = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->sole());
    $lineUid = $this->inCompany($this->company, fn () => BoqItem::query()->whereKey($this->boqItemId())->value('line_uid'));

    expect($issue->status)->toBe(InventoryDocumentStatus::Approved)
        ->and($issue->approved_by)->toBe($this->pm->id)
        ->and($txn->qty_out)->toBe('5.0000')
        ->and($txn->unit_cost)->toBe('110.0000')
        ->and($txn->value)->toBe('550.00')
        ->and($txn->source_type)->toBe('material_issue_item')
        ->and($line->unit_cost)->toBe('110.0000')
        ->and($line->amount)->toBe('550.00')
        ->and($cost->cost_head)->toBe(CostHead::Material)
        ->and($cost->amount)->toBe($txn->value)
        ->and($cost->boq_item_id)->toBe($this->boqItemId())
        ->and($cost->boq_line_uid)->toBe($lineUid)
        ->and($cost->task_id)->toBe($this->task->id)
        ->and($cost->project_id)->toBe($this->project->id)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '15.0000', 'avg_cost' => '110.0000', 'value' => '1650.00']);
});

test('approval with insufficient stock fails and rolls the whole approval back', function () {
    $issue = $this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '15']]);
    $this->inCompany($this->company, fn () => app(MaterialIssueService::class)->submit($issue->fresh(), $this->storekeeper));
    $this->stockOut($this->cement, '10');   // stock drops to 10 after submission

    $request = $this->inCompany($this->company, fn () => $issue->fresh()->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('approvals.approve', $request->id))
        ->assertSessionHasErrors('items');

    expect(issueOf($this, $issue->id)->status)->toBe(InventoryDocumentStatus::Submitted)
        ->and($this->inCompany($this->company, fn () => $request->fresh()->status))->toBe(ApprovalStatus::Pending)
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::IssueOut)->where('source_type', 'material_issue_item')->count()))->toBe(0)
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()))->toBe(0)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('10.0000');
});

test('submitting an issue larger than the stock is refused early', function () {
    $issue = $this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '12'], ['material_id' => $this->cement->id, 'quantity' => '9']]);

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.material-issues.submit', [$this->project, $issue]))
        ->assertSessionHasErrors(['items' => 'Insufficient stock of Cement OPC 53 in Tower A site store: available 20.0000, required 21.0000.']);
});

test('two approvals competing for the same stock: only the first posts', function () {
    $this->stockOut($this->cement, '10');   // 10 left
    $a = $this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '8']]);
    $b = $this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '8']]);
    $this->inCompany($this->company, function () use ($a, $b) {
        app(MaterialIssueService::class)->submit($a->fresh(), $this->storekeeper);
        app(MaterialIssueService::class)->submit($b->fresh(), $this->storekeeper);
    });

    $this->inCompany($this->company, fn () => app(ApprovalService::class)->approve($a->fresh()->pendingApprovalRequest(), $this->pm));
    expect(fn () => $this->inCompany($this->company, fn () => app(ApprovalService::class)->approve($b->fresh()->pendingApprovalRequest(), $this->pm)))
        ->toThrow(ValidationException::class, 'Insufficient stock');

    expect(issueOf($this, $a->id)->status)->toBe(InventoryDocumentStatus::Approved)
        ->and(issueOf($this, $b->id)->status)->toBe(InventoryDocumentStatus::Submitted)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('2.0000');
});

test('retrying the posting of an approved issue changes nothing', function () {
    $issue = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '4']]));

    $this->inCompany($this->company, fn () => app(MaterialIssueService::class)->post($issue->fresh(), $this->pm->id));
    $this->inCompany($this->company, fn () => $issue->fresh()->onApprovalCompleted());

    expect($this->inCompany($this->company, fn () => StockTransaction::query()->where('source_type', 'material_issue_item')->count()))->toBe(1)
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()))->toBe(1)
        ->and($this->balanceOf($this->cement)['quantity'])->toBe('16.0000');
});

test('a posted issue is immutable', function () {
    $issue = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '4']]));
    $store = $this->actingInCompany($this->storekeeper, $this->company);

    $store->put(route('projects.material-issues.update', [$this->project, $issue]), issuePayload($this))->assertForbidden();
    $store->delete(route('projects.material-issues.destroy', [$this->project, $issue]))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => MaterialIssueItem::query()->first()->forceFill(['quantity' => '1'])->save()))
        ->toThrow(ValidationException::class);
});

test('cancelling a posted issue reverses stock and cost, needs approve_adjustment and is blocked by returns', function () {
    $issue = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '6', 'boq_item_id' => $this->boqItemId()]]));

    $this->actingInCompany($this->storekeeper, $this->company)
        ->post(route('projects.material-issues.cancel', [$this->project, $issue]), ['reason' => 'Issued in error'])
        ->assertForbidden();

    $admin = $this->actingInCompany($this->admin, $this->company);
    $admin->post(route('projects.material-issues.cancel', [$this->project, $issue]), [])->assertSessionHasErrors('reason');
    $admin->post(route('projects.material-issues.cancel', [$this->project, $issue]), ['reason' => 'Issued in error'])->assertSessionHasNoErrors();

    $costs = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->orderBy('id')->get());
    expect(issueOf($this, $issue->id)->status)->toBe(InventoryDocumentStatus::Cancelled)
        ->and($this->balanceOf($this->cement))->toBe(['quantity' => '20.0000', 'avg_cost' => '110.0000', 'value' => '2200.00'])
        ->and($costs->pluck('amount')->all())->toBe(['660.00', '-660.00'])
        ->and($costs[1]->reverses_id)->toBe($costs[0]->id)
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->where('txn_type', StockTxnType::Reversal)->count()))->toBe(1);

    // An issue with a pending site return cannot be cancelled.
    $second = $this->approveIssue($this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '3']]));
    $this->inCompany($this->company, function () use ($second) {
        $return = app(MaterialReturnService::class)->create($this->project, MaterialReturnType::SiteToStore, [
            'return_date' => now()->toDateString(), 'warehouse_id' => $this->siteStore()->id, 'reason' => 'Excess',
            'items' => [['material_issue_item_id' => $second->items()->value('id'), 'quantity' => '1']],
        ]);
        app(MaterialReturnService::class)->submit($return, $this->storekeeper);
    });
    $admin->post(route('projects.material-issues.cancel', [$this->project, $second]), ['reason' => 'Issued in error'])
        ->assertSessionHasErrors('issue');
});

test('issues validate store, BOQ line and task against the project', function () {
    $store = $this->actingInCompany($this->storekeeper, $this->company);
    $otherBoqLine = $this->inCompany($this->company, fn () => $this->approveBoq($this->makeBoq(null, $this->otherProject, 'Tower B works'))->items()->value('id'));

    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['warehouse_id' => $this->otherStore->id]))->assertSessionHasErrors('warehouse_id');
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['items' => [['material_id' => $this->cement->id, 'quantity' => '1', 'boq_item_id' => $otherBoqLine]]]))->assertSessionHasErrors('items.0.boq_item_id');
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['items' => [['material_id' => $this->cement->id, 'quantity' => '0']]]))->assertSessionHasErrors('items.0.quantity');
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['issued_to_user_id' => $this->purchaser->id + 1000]))->assertSessionHasErrors('issued_to_user_id');
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['issued_to_user_id' => null]))->assertSessionHasErrors('issued_to_name');

    // The central store is reachable from every project.
    $store->post(route('projects.material-issues.store', $this->project), issuePayload($this, ['warehouse_id' => $this->central->id]))->assertSessionHasNoErrors();
});

test('issue screens: permission, project access and foreign ids', function () {
    $issue = $this->makeIssue([['material_id' => $this->cement->id, 'quantity' => '1']]);
    $outsider = $this->createMember($this->company, DefaultRoles::STORE_MANAGER);
    $otherCompany = $this->createCompany();
    $stranger = $this->createMember($otherCompany, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($this->storekeeper, $this->company)
        ->get(route('projects.material-issues.show', [$this->project, $issue]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Issues/Show')
            ->where('can.submit', true)->where('can.cancel', false)->missing('items.0.unit_cost'));

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.material-issues.create', $this->project))->assertForbidden();
    $this->actingInCompany($outsider, $this->company)->get(route('projects.material-issues.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->storekeeper, $this->company)->get(route('projects.material-issues.show', [$this->otherProject, $issue]))->assertNotFound();
    $this->actingInCompany($stranger, $otherCompany)->get(route('projects.material-issues.show', [$this->project, $issue]))->assertNotFound();

    $this->actingInCompany($this->director, $this->company)
        ->get(route('projects.material-issues.show', [$this->project, $issue]))
        ->assertInertia(fn (Assert $page) => $page->has('items.0.unit_cost')->where('can.submit', false));
});
