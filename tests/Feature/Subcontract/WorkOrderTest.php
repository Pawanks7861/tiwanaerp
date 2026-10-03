<?php

use App\Enums\Subcontract\MilestoneStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Masters\Subcontractor;
use App\Models\Subcontract\WorkOrder;
use App\Services\Approval\ApprovalService;
use App\Services\Subcontract\WorkOrderService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

beforeEach(function () {
    $this->setUpResources();
});

test('work order CRUD numbers the order, links BOQ lines by line uid and computes subtotal, GST and total', function () {
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.work-orders.store', $this->project), $this->woPayload(['company_id' => 999, 'project_id' => $this->otherProject->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $order = $this->inCompany($this->company, fn () => WorkOrder::query()->sole());
    $items = $this->inCompany($this->company, fn () => $order->items()->orderBy('id')->get());
    expect($order->wo_number)->toBe('WO-PRJ001-0001')
        ->and($order->company_id)->toBe($this->company->id)
        ->and($order->project_id)->toBe($this->project->id)
        ->and($order->status)->toBe(WorkOrderStatus::Draft)
        ->and($order->subtotal)->toBe('61000.00')
        ->and($order->tax_percent)->toBe('18.0000')
        ->and($order->tax_amount)->toBe('10980.00')
        ->and($order->total_value)->toBe('71980.00')
        ->and($order->advance_amount)->toBe('5000.00')
        ->and($items->pluck('amount')->all())->toBe(['25000.00', '36000.00'])
        ->and($items->pluck('boq_line_uid')->unique()->all())->toBe([$this->boqLine->line_uid])
        ->and($items[0]->task_id)->toBe($this->task->id)
        ->and($items->pluck('certified_qty')->all())->toBe(['0.0000', '0.0000']);

    // Editing a draft keeps the BOQ line uid; the totals follow the new quantities.
    $payload = $this->woPayload();
    $payload['items'][1]['quantity'] = '10';
    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.work-orders.update', [$this->project, $order]), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $order->refresh();
    expect($order->subtotal)->toBe('43000.00')
        ->and($order->tax_amount)->toBe('7740.00')
        ->and($order->total_value)->toBe('50740.00')
        ->and($this->inCompany($this->company, fn () => $order->items()->pluck('boq_line_uid')->unique()->all()))->toBe([$this->boqLine->line_uid]);

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.work-orders.show', [$this->project, $order]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Subcontract/WorkOrders/Show')
            ->where('order.wo_number', 'WO-PRJ001-0001')
            ->where('items.0.boq_line_uid', $this->boqLine->line_uid)
            ->where('can.submit', true)
            ->has('milestones', 2));

    // The next order takes the next number; a draft can be deleted.
    $second = $this->makeWorkOrder();
    expect($second->wo_number)->toBe('WO-PRJ001-0002');
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.work-orders.destroy', [$this->project, $second]))
        ->assertRedirect(route('projects.work-orders.index', $this->project));
    expect($this->inCompany($this->company, fn () => WorkOrder::query()->whereKey($second->id)->exists()))->toBeFalse();
});

test('work order validation: subcontractor, dates, milestones, advance, BOQ line and task of another project', function () {
    $as = fn () => $this->actingInCompany($this->billing, $this->company);
    $store = route('projects.work-orders.store', $this->project);

    $inactive = $this->inCompany($this->company, fn () => Subcontractor::query()->create(['code' => 'SUB-X', 'name' => 'Old', 'is_active' => false]));
    $as()->post($store, $this->woPayload(['subcontractor_id' => $inactive->id]))->assertSessionHasErrors('subcontractor_id');
    $as()->post($store, $this->woPayload(['start_date' => now()->toDateString(), 'end_date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('end_date');
    $as()->post($store, $this->woPayload(['milestones' => [['name' => 'A', 'amount_percent' => '60'], ['name' => 'B', 'amount_percent' => '50']]]))->assertSessionHasErrors('milestones');
    $as()->post($store, $this->woPayload(['advance_amount' => '71980.01']))->assertSessionHasErrors('advance_amount');
    $as()->post($store, $this->woPayload(['items' => []]))->assertSessionHasErrors('items');
    $as()->post($store, $this->woPayload(['items' => [['description' => 'X', 'unit_id' => $this->unitId(), 'quantity' => '0', 'rate' => '10']]]))->assertSessionHasErrors('items.0.quantity');

    $foreignTask = $this->makeTask(['wbs_code' => '7.1', 'name' => 'Tower B task'], $this->otherProject);
    $as()->post($store, $this->woPayload(['items' => [['task_id' => $foreignTask->id, 'description' => 'X', 'unit_id' => $this->unitId(), 'quantity' => '1', 'rate' => '10']]]))
        ->assertSessionHasErrors('items.0.task_id');
    $as()->post($store, $this->woPayload(['items' => [['boq_item_id' => 999999, 'description' => 'X', 'unit_id' => $this->unitId(), 'quantity' => '1', 'rate' => '10']]]))
        ->assertSessionHasErrors('items.0.boq_item_id');

    // The advance exactly equal to the total is fine.
    $as()->post($store, $this->woPayload(['advance_amount' => '71980.00']))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => WorkOrder::query()->count()))->toBe(1);
});

test('work order approval: submit locks, PM then Director approves, reject returns it for editing', function () {
    $order = $this->makeWorkOrder();

    $this->actingInCompany($this->billing, $this->company)->post(route('projects.work-orders.submit', [$this->project, $order]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(WorkOrderStatus::Submitted);

    // Submitted: not editable, not deletable.
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.update', [$this->project, $order]), $this->woPayload())->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.work-orders.destroy', [$this->project, $order]))->assertForbidden();

    // Director rejects at level 2 after PM approval.
    $this->inCompany($this->company, function () use ($order) {
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($order->fresh()->pendingApprovalRequest(), $this->pm);
        expect($order->fresh()->status)->toBe(WorkOrderStatus::Submitted);
        $approvals->reject($request, $this->director, 'Rates too high');
    });
    expect($order->fresh()->status)->toBe(WorkOrderStatus::Rejected);

    // Rejected → edit and resubmit → approved.
    $payload = $this->woPayload();
    $payload['items'][0]['rate'] = '240';
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.update', [$this->project, $order]), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $order = $this->approveWorkOrder($order);
    expect($order->status)->toBe(WorkOrderStatus::Approved)
        ->and($order->approved_by)->toBe($this->director->id)
        ->and($order->approved_at)->not->toBeNull()
        ->and($order->subtotal)->toBe('60000.00')
        ->and($order->total_value)->toBe('70800.00');

    // Approved orders are locked: no edit through HTTP or the service, no delete.
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.update', [$this->project, $order]), $this->woPayload())->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(WorkOrderService::class)->update($order, $this->woPayload())))->toThrow(Exception::class);
    expect(fn () => $this->inCompany($this->company, fn () => app(WorkOrderService::class)->delete($order)))->toThrow(ValidationException::class);
    expect($order->fresh()->subtotal)->toBe('60000.00');
});

test('final approval needs subcontract.approve_wo even when the workflow names the approver', function () {
    $order = $this->makeWorkOrder();
    $this->inCompany($this->company, fn () => app(WorkOrderService::class)->submit($order, $this->billing));

    // The service refuses a final approver without the permission (PM lacks approve_wo).
    expect(fn () => $this->inCompany($this->company, fn () => app(WorkOrderService::class)->approve($order->fresh(), $this->pm->id)))
        ->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe(WorkOrderStatus::Submitted);

    // Idempotent once approved.
    $this->inCompany($this->company, function () use ($order) {
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($order->fresh()->pendingApprovalRequest(), $this->pm), $this->director);
        $approvedAt = $order->fresh()->approved_at;
        app(WorkOrderService::class)->approve($order->fresh(), $this->director->id);
        expect($order->fresh()->approved_at->equalTo($approvedAt))->toBeTrue();
    });
});

test('milestones are tracked on an approved order; complete, close and cancel follow the lifecycle', function () {
    $draft = $this->makeWorkOrder();
    $milestones = $this->inCompany($this->company, fn () => $draft->milestones()->orderBy('id')->get());
    expect($milestones->pluck('amount_percent')->all())->toBe(['40.0000', '60.0000']);

    // Not on a draft.
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.milestones', [$this->project, $draft]), [
        'milestones' => [['id' => $milestones[0]->id, 'status' => 'achieved']],
    ])->assertForbidden();

    $order = $this->approveWorkOrder($draft);
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.milestones', [$this->project, $order]), [
        'milestones' => [['id' => $milestones[0]->id, 'status' => 'achieved']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($milestones[0]->fresh()->status)->toBe(MilestoneStatus::Achieved)
        ->and($milestones[0]->fresh()->achieved_at)->not->toBeNull()
        ->and($milestones[1]->fresh()->status)->toBe(MilestoneStatus::Pending);

    // A milestone of another order is rejected.
    $other = $this->approveWorkOrder($this->makeWorkOrder());
    $foreign = $this->inCompany($this->company, fn () => $other->milestones()->value('id'));
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.work-orders.milestones', [$this->project, $order]), [
        'milestones' => [['id' => $foreign, 'status' => 'achieved']],
    ])->assertSessionHasErrors('milestones.0.id');

    // Only approve_wo holders (Director) complete / close / cancel; PM cannot.
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.work-orders.complete', [$this->project, $order]))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->post(route('projects.work-orders.cancel', [$this->project, $other]), ['reason' => 'Scope moved'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe(WorkOrderStatus::Cancelled)
        ->and($other->fresh()->cancellation_reason)->toBe('Scope moved');

    $this->actingInCompany($this->director, $this->company)->post(route('projects.work-orders.complete', [$this->project, $order]))->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(WorkOrderStatus::Completed);
    $this->actingInCompany($this->director, $this->company)->post(route('projects.work-orders.close', [$this->project, $order]))->assertRedirect()->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(WorkOrderStatus::Closed)
        ->and($order->fresh()->closed_by)->toBe($this->director->id);

    // A closed order takes no bills.
    expect(fn () => $this->makeBill($order->fresh(), ['10']))->toThrow(ValidationException::class);
});

test('work orders are isolated by tenant and project and need subcontract permissions', function () {
    $order = $this->makeWorkOrder();

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)->get(route('projects.work-orders.index', $this->project))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->get(route('projects.work-orders.show', [$this->project, $order]))->assertNotFound();

    // A subcontractor of another company cannot be used.
    $foreignSub = $this->inCompany($other, fn () => Subcontractor::query()->create(['code' => 'FS1', 'name' => 'Foreign sub']));
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.work-orders.store', $this->project), $this->woPayload(['subcontractor_id' => $foreignSub->id]))
        ->assertSessionHasErrors('subcontractor_id');

    // An order of another project is not reachable through this project's URL.
    $towerB = $this->inCompany($this->company, fn () => app(WorkOrderService::class)->create($this->otherProject, $this->woPayload(['items' => [
        ['description' => 'Tower B work', 'unit_id' => $this->unitId(), 'quantity' => '1', 'rate' => '100'],
    ], 'milestones' => [], 'advance_amount' => '0'])));
    expect($towerB->wo_number)->toStartWith('WO-');
    $this->actingInCompany($this->admin, $this->company)->get(route('projects.work-orders.show', [$this->project, $towerB]))->assertNotFound();

    // Site engineer has no subcontract access; accountant views only; a non-member billing engineer cannot view.
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.work-orders.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.work-orders.index', $this->project))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('can.create', false));
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.work-orders.store', $this->project), $this->woPayload())->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.work-orders.submit', [$this->project, $order]))->assertForbidden();
    $stranger = $this->createMember($this->company, DefaultRoles::BILLING_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->get(route('projects.work-orders.index', $this->project))->assertForbidden();

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.work-orders.create', $this->project))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Subcontract/WorkOrders/Form')
        ->where('options.boq_items', fn ($rows) => collect($rows)->contains('value', $this->boqLine->id)));
});
