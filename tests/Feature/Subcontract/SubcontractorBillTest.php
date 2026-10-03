<?php

use App\Enums\CostHead;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\SubcontractorBillItem;
use App\Services\Approval\ApprovalService;
use App\Services\Subcontract\SubcontractorBillService;
use App\Services\Subcontract\WorkOrderService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

beforeEach(function () {
    $this->setUpResources();
    // 100 Sqm formwork @ 250 and 20 Cum PCC @ 1,800; retention 5%, advance 5,000, GST 18%, TDS 1%.
    $this->order = $this->approveWorkOrder($this->makeWorkOrder());
    $this->lines = $this->inCompany($this->company, fn () => $this->order->items()->orderBy('id')->get()->values());
});

function billItems($test, SubcontractorBill $bill)
{
    return $test->inCompany($test->company, fn () => $bill->items()->orderBy('work_order_item_id')->get()->values());
}

test('a bill is raised against an approved work order only, from the work order lines', function () {
    $draftOrder = $this->makeWorkOrder();
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.store', $this->project), [
        'work_order_id' => $draftOrder->id, 'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $this->inCompany($this->company, fn () => $draftOrder->items()->value('id')), 'claimed_qty' => '1']],
    ])->assertSessionHasErrors('work_order_id');

    $this->actingInCompany($this->billing, $this->company)->get(route('projects.subcontractor-bills.create', [$this->project, 'work_order_id' => $this->order->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Subcontract/Bills/Form')->has('workOrders', 1));

    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.store', $this->project), [
        'work_order_id' => $this->order->id, 'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'subcontractor_invoice_no' => 'SF/101', 'advance_recovery' => '2000', 'company_id' => 999,
        'items' => [
            ['work_order_item_id' => $this->lines[0]->id, 'claimed_qty' => '40'],
            ['work_order_item_id' => $this->lines[1]->id, 'claimed_qty' => '10'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $bill = $this->inCompany($this->company, fn () => SubcontractorBill::query()->sole());
    expect($bill->bill_number)->toBe('SCB-PRJ001-0001')
        ->and($bill->company_id)->toBe($this->company->id)
        ->and($bill->subcontractor_id)->toBe($this->subcontractor->id)
        ->and($bill->status)->toBe(SubcontractorBillStatus::Draft)
        ->and($bill->gross_amount)->toBe('28000.00')
        ->and($this->costs())->toHaveCount(0);

    // A line not on the work order, a duplicate line, or nothing claimed is rejected.
    $foreignLine = $this->inCompany($this->company, fn () => $draftOrder->items()->value('id'));
    expect(fn () => $this->makeBill($this->order, []))->toThrow(ValidationException::class);
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.store', $this->project), [
        'work_order_id' => $this->order->id, 'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $foreignLine, 'claimed_qty' => '1']],
    ])->assertSessionHasErrors('items.0.work_order_item_id');
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.store', $this->project), [
        'work_order_id' => $this->order->id, 'bill_date' => now()->addDay()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $this->lines[0]->id, 'claimed_qty' => '1']],
    ])->assertSessionHasErrors('bill_date');
});

test('certification: certifier lowers a quantity, amounts are exact, cost posts per line excluding GST', function () {
    $bill = $this->makeBill($this->order, ['40', '10'], ['advance_recovery' => '2000']);
    $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->submit($bill, $this->billing));
    expect($bill->fresh()->status)->toBe(SubcontractorBillStatus::Submitted);

    // PM (level 1) adjusts formwork 40 → 30 → 35 (lower, then back up within the claim); not above the claim.
    $adjust = fn (string $qty) => $this->actingInCompany($this->pm, $this->company)->put(route('projects.subcontractor-bills.adjust', [$this->project, $bill]), [
        'items' => [['id' => billItems($this, $bill)[0]->id, 'certified_qty' => $qty]], 'advance_recovery' => '2000',
    ]);
    $adjust('30')->assertRedirect()->assertSessionHasNoErrors();
    expect(billItems($this, $bill)->every(fn ($i) => Decimal::of($i->cumulative_qty)->equals(Decimal::of($i->previous_qty)->plus($i->certified_qty))))->toBeTrue();
    $adjust('35')->assertRedirect()->assertSessionHasNoErrors();
    $adjust('40.0001')->assertSessionHasErrors('items.0.certified_qty');
    // The Director is not the current approver yet.
    $this->actingInCompany($this->director, $this->company)->put(route('projects.subcontractor-bills.adjust', [$this->project, $bill]), [
        'items' => [['id' => billItems($this, $bill)[0]->id, 'certified_qty' => '1']],
    ])->assertForbidden();

    $this->inCompany($this->company, function () use ($bill) {
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($bill->fresh()->pendingApprovalRequest(), $this->pm), $this->director);
    });

    $bill->refresh();
    $items = billItems($this, $bill);
    // Hand check: 35 × 250 = 8,750; 10 × 1,800 = 18,000; gross 26,750; GST 4,815; retention 1,337.50;
    // TDS 267.50; advance 2,000; net = 26,750 + 4,815 − 1,337.50 − 2,000 − 267.50 = 27,960.00.
    expect($bill->status)->toBe(SubcontractorBillStatus::Certified)
        ->and($bill->certified_by)->toBe($this->director->id)
        ->and($items->pluck('claimed_qty')->all())->toBe(['40.0000', '10.0000'])
        ->and($items->pluck('certified_qty')->all())->toBe(['35.0000', '10.0000'])
        ->and($items->pluck('previous_qty')->all())->toBe(['0.0000', '0.0000'])
        ->and($items->pluck('cumulative_qty')->all())->toBe(['35.0000', '10.0000'])
        ->and($items->pluck('amount')->all())->toBe(['8750.00', '18000.00'])
        ->and($bill->gross_amount)->toBe('26750.00')
        ->and($bill->tax_amount)->toBe('4815.00')
        ->and($bill->retention_amount)->toBe('1337.50')
        ->and($bill->tds_amount)->toBe('267.50')
        ->and($bill->advance_recovery)->toBe('2000.00')
        ->and($bill->net_payable)->toBe('27960.00');

    $costs = $this->costs(CostHead::Subcontract);
    expect($costs)->toHaveCount(2)
        ->and($costs->pluck('amount')->all())->toBe(['8750.00', '18000.00'])
        ->and($costs->pluck('source_type')->unique()->all())->toBe(['subcontractor_bill_item'])
        ->and($costs->pluck('source_id')->all())->toBe($items->pluck('id')->all())
        ->and($costs->pluck('boq_line_uid')->unique()->all())->toBe([$this->boqLine->line_uid])
        ->and($costs->pluck('boq_item_id')->unique()->all())->toBe([$this->boqLine->id])
        ->and($costs[0]->task_id)->toBe($this->task->id)
        ->and($costs[1]->task_id)->toBeNull()
        ->and($costs[0]->entry_date->toDateString())->toBe($bill->bill_date->toDateString())
        ->and($costs->every(fn ($c) => $c->project_id === $this->project->id))->toBeTrue()
        ->and($this->netCost(CostHead::Subcontract))->toBe('26750.00');

    // The work order tracks certified quantities and moves to in progress.
    $order = $this->order->fresh();
    expect($order->status)->toBe(WorkOrderStatus::InProgress)
        ->and($this->lines[0]->fresh()->certified_qty)->toBe('35.0000')
        ->and($this->lines[1]->fresh()->certified_qty)->toBe('10.0000');

    // Retry safety: certifying again posts nothing.
    $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->certify($bill->fresh(), $this->director->id));
    expect($this->costs(CostHead::Subcontract))->toHaveCount(2);

    // Certified lock: no edit, adjust, delete or resubmit.
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.subcontractor-bills.update', [$this->project, $bill]), [
        'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $this->lines[0]->id, 'claimed_qty' => '1']],
    ])->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.subcontractor-bills.destroy', [$this->project, $bill]))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->adjust($bill->fresh(), ['items' => []])))->toThrow(ValidationException::class);
    expect(fn () => $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->submit($bill->fresh(), $this->billing)))->toThrow(Exception::class);
});

test('previous and cumulative quantities carry across bills; over-certification and over-recovery are blocked', function () {
    $first = $this->certifyBill($this->makeBill($this->order, ['35', '10'], ['advance_recovery' => '2000']));
    expect($first->net_payable)->toBe('27960.00');

    // Over-certification: 35 already certified, 66 more would exceed 100.
    expect(fn () => $this->makeBill($this->order, ['66']))->toThrow(ValidationException::class);
    // Advance: 3,000 left to recover.
    expect(fn () => $this->makeBill($this->order, ['65', '10'], ['advance_recovery' => '3000.01']))->toThrow(ValidationException::class);

    $second = $this->makeBill($this->order, ['65', '10'], ['advance_recovery' => '3000']);
    $items = billItems($this, $second);
    expect($items->pluck('previous_qty')->all())->toBe(['35.0000', '10.0000'])
        ->and($items->pluck('cumulative_qty')->all())->toBe(['100.0000', '20.0000']);

    // While the second bill is submitted, a third bill on the same quantities cannot be submitted.
    $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->submit($second, $this->billing));
    $third = $this->makeBill($this->order, ['1']);
    expect(fn () => $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->submit($third->fresh(), $this->billing)))->toThrow(ValidationException::class);
    $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->delete($third->fresh()));

    $this->inCompany($this->company, function () use ($second) {
        $approvals = app(ApprovalService::class);
        $approvals->approve($approvals->approve($second->fresh()->pendingApprovalRequest(), $this->pm), $this->director);
    });
    $second->refresh();
    // Hand check: 65 × 250 = 16,250 + 18,000 = 34,250; GST 6,165; retention 1,712.50; TDS 342.50;
    // advance 3,000; net = 34,250 + 6,165 − 1,712.50 − 3,000 − 342.50 = 35,360.00.
    expect($second->status)->toBe(SubcontractorBillStatus::Certified)
        ->and($second->gross_amount)->toBe('34250.00')
        ->and($second->tax_amount)->toBe('6165.00')
        ->and($second->retention_amount)->toBe('1712.50')
        ->and($second->tds_amount)->toBe('342.50')
        ->and($second->advance_recovery)->toBe('3000.00')
        ->and($second->net_payable)->toBe('35360.00')
        ->and($this->lines[0]->fresh()->certified_qty)->toBe('100.0000')
        ->and($this->lines[1]->fresh()->certified_qty)->toBe('20.0000')
        ->and($this->netCost(CostHead::Subcontract))->toBe('61000.00')
        ->and($this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->advanceBalance($this->order->fresh())->toMoney()))->toBe('0.00');

    // Fully certified: any further quantity is over-certification.
    expect(fn () => $this->makeBill($this->order, ['0.0001']))->toThrow(ValidationException::class);
});

test('deductions cannot exceed the bill and the advance recovery cannot exceed gross', function () {
    // Gross 250; advance recovery 251 > gross.
    expect(fn () => $this->makeBill($this->order, ['1'], ['advance_recovery' => '251']))->toThrow(ValidationException::class);
    // Net = 250 + 45 − 12.50 − 2.50 = 280; other deductions 280.01 makes it negative.
    expect(fn () => $this->makeBill($this->order, ['1'], ['other_deductions' => '280.01']))->toThrow(ValidationException::class);
    $bill = $this->makeBill($this->order, ['1'], ['other_deductions' => '280']);
    expect($bill->net_payable)->toBe('0.00');
});

test('reversal: only the latest bill, cost reversed append-only, re-certification posts once more', function () {
    $first = $this->certifyBill($this->makeBill($this->order, ['35', '10'], ['advance_recovery' => '2000']));
    $second = $this->certifyBill($this->makeBill($this->order, ['20']));
    expect($this->netCost(CostHead::Subcontract))->toBe('31750.00');

    // Billing engineer lacks certify_bill.
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.reverse', [$this->project, $second]), ['reason' => 'Wrong measurement'])->assertForbidden();
    // The earlier bill cannot be reversed while a later one is certified.
    $this->actingInCompany($this->director, $this->company)->post(route('projects.subcontractor-bills.reverse', [$this->project, $first]), ['reason' => 'Wrong measurement'])
        ->assertSessionHasErrors('bill');

    $this->actingInCompany($this->director, $this->company)->post(route('projects.subcontractor-bills.reverse', [$this->project, $second]), ['reason' => 'Wrong measurement'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $second->refresh();
    expect($second->status)->toBe(SubcontractorBillStatus::Draft)
        ->and($second->revision)->toBe(1)
        ->and($second->reopened_by)->toBe($this->director->id)
        ->and($this->lines[0]->fresh()->certified_qty)->toBe('35.0000')
        ->and($this->netCost(CostHead::Subcontract))->toBe('26750.00');

    $rows = $this->costs(CostHead::Subcontract);
    expect($rows->pluck('amount')->all())->toBe(['8750.00', '18000.00', '5000.00', '-5000.00'])
        ->and($rows->last()->is_reversal)->toBeTrue();

    // A once-certified bill cannot be deleted; correct it and re-certify.
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.subcontractor-bills.destroy', [$this->project, $second]))->assertForbidden();
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.subcontractor-bills.update', [$this->project, $second]), [
        'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $this->lines[0]->id, 'claimed_qty' => '18']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $second = $this->certifyBill($second);

    $rows = $this->costs(CostHead::Subcontract);
    expect($rows->pluck('amount')->all())->toBe(['8750.00', '18000.00', '5000.00', '-5000.00', '4500.00'])
        ->and($rows->last()->posting_ref)->toBe('r1')
        ->and($rows->last()->source_id)->toBe($rows[2]->source_id)
        ->and($this->inCompany($this->company, fn () => SubcontractorBillItem::query()->whereKey($rows[2]->source_id)->exists()))->toBeTrue()
        ->and($this->netCost(CostHead::Subcontract))->toBe('31250.00')
        ->and($this->lines[0]->fresh()->certified_qty)->toBe('53.0000');

    // Reverse the second bill again and move its claim to the PCC line: the formwork line keeps its
    // ledger history (zeroed, not deleted), so every ledger row still points at an existing bill line.
    $this->actingInCompany($this->director, $this->company)->post(route('projects.subcontractor-bills.reverse', [$this->project, $second]), ['reason' => 'Moved to PCC'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingInCompany($this->billing, $this->company)->put(route('projects.subcontractor-bills.update', [$this->project, $second]), [
        'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => $this->lines[1]->id, 'claimed_qty' => '2']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->certifyBill($second);

    $items = billItems($this, $second);
    expect($items->pluck('certified_qty')->all())->toBe(['0.0000', '2.0000'])
        ->and($items->pluck('amount')->all())->toBe(['0.00', '3600.00'])
        ->and($this->costs(CostHead::Subcontract)->pluck('source_id')->diff($this->inCompany($this->company, fn () => SubcontractorBillItem::query()->pluck('id')))->all())->toBe([])
        ->and($this->netCost(CostHead::Subcontract))->toBe('30350.00')
        ->and($this->lines[0]->fresh()->certified_qty)->toBe('35.0000')
        ->and($this->lines[1]->fresh()->certified_qty)->toBe('12.0000');
});

test('bills are isolated by tenant and project and need subcontract permissions', function () {
    $bill = $this->makeBill($this->order, ['10']);

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)->get(route('projects.subcontractor-bills.show', [$this->project, $bill]))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->post(route('projects.subcontractor-bills.submit', [$this->project, $bill]))->assertNotFound();

    // A work order of another project cannot be billed through this project.
    $towerB = $this->inCompany($this->company, fn () => app(WorkOrderService::class)->create($this->otherProject, $this->woPayload([
        'items' => [['description' => 'Tower B work', 'unit_id' => $this->unitId(), 'quantity' => '1', 'rate' => '100']], 'milestones' => [], 'advance_amount' => '0',
    ])));
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.subcontractor-bills.store', $this->project), [
        'work_order_id' => $towerB->id, 'bill_date' => now()->toDateString(), 'period_from' => now()->subWeek()->toDateString(), 'period_to' => now()->toDateString(),
        'items' => [['work_order_item_id' => 1, 'claimed_qty' => '1']],
    ])->assertSessionHasErrors('work_order_id');

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.subcontractor-bills.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.subcontractor-bills.show', [$this->project, $bill]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Subcontract/Bills/Show')
        ->where('bill.bill_number', 'SCB-PRJ001-0001')
        ->where('can.update', false)
        ->has('items', 1));
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.subcontractor-bills.submit', [$this->project, $bill]))->assertForbidden();

    // Certifying needs certify_bill: a final approver without it is refused by the service.
    $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->submit($bill->fresh(), $this->billing));
    expect(fn () => $this->inCompany($this->company, fn () => app(SubcontractorBillService::class)->certify($bill->fresh(), $this->billing->id)))
        ->toThrow(ValidationException::class);
    expect($bill->fresh()->status)->toBe(SubcontractorBillStatus::Submitted)
        ->and($this->inCompany($this->company, fn () => SubcontractorBillItem::query()->count()))->toBe(1)
        ->and($this->costs())->toHaveCount(0);
});
