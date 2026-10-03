<?php

use App\Enums\CostHead;
use App\Enums\Inventory\StockTxnType;
use App\Enums\ProjectRole;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Labour\LabourAttendance;
use App\Models\Masters\Warehouse;
use App\Models\Subcontract\SubcontractorBillItem;
use App\Services\Approval\ApprovalService;
use App\Services\Equipment\EquipmentFuelService;
use App\Services\Equipment\EquipmentRepairService;
use App\Services\Equipment\EquipmentUsageService;
use App\Services\Inventory\MaterialIssueService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockMovement;
use App\Services\Labour\LabourAdvanceService;
use App\Services\Labour\LabourPaymentService;
use App\Services\Projects\ProjectService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * One project receives cost from all four generators: a material issue (Phase 4), approved labour
 * attendance, a certified subcontractor bill and posted equipment usage. Payments, advances, fuel
 * and repairs add nothing. Every ledger row must equal its generating document line.
 */
beforeEach(function () {
    $this->setUpResources();
    $date = now()->subDay()->toDateString();

    // Material: 10 bags @ 100 into the site store, 4 issued on BOQ A.1 / task → 400.
    $storekeeper = $this->createMember($this->company, DefaultRoles::STORE_MANAGER);
    $this->inCompany($this->company, function () use ($storekeeper, $date) {
        app(ProjectService::class)->assignMember($this->project, $storekeeper->id, ProjectRole::Store);
        $store = Warehouse::query()->create(['project_id' => $this->project->id, 'code' => 'WH-SITE', 'name' => 'Site store', 'type' => 'site']);
        app(StockLedgerService::class)->post(new StockMovement(
            source: (new StockAdjustmentItem)->forceFill(['id' => 1_000_001]),
            type: StockTxnType::GrnIn,
            warehouse: $store,
            materialId: $this->cement->id,
            quantity: Decimal::of('10'),
            date: $date,
            projectId: $this->project->id,
            unitCost: Decimal::of('100'),
        ));
        $issue = app(MaterialIssueService::class)->create($this->project, [
            'warehouse_id' => $store->id, 'issue_date' => $date, 'issued_to_name' => 'Foreman',
            'items' => [['material_id' => $this->cement->id, 'quantity' => '4', 'boq_item_id' => $this->boqLine->id, 'task_id' => $this->task->id]],
        ]);
        app(MaterialIssueService::class)->submit($issue->fresh(), $storekeeper);
        app(ApprovalService::class)->approve($issue->fresh()->pendingApprovalRequest(), $this->pm);
    });

    // Labour: Ravi present + 2 h OT = 800 + 240; Sunil half day = 300 → 1,340.
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2', 'task_id' => $this->task->id],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day', 'task_id' => $this->task->id],
    ], $date);

    // Subcontract: 35 Sqm × 250 + 10 Cum × 1,800 = 26,750 (GST excluded from cost).
    $this->order = $this->approveWorkOrder($this->makeWorkOrder());
    $this->bill = $this->certifyBill($this->makeBill($this->order, ['35', '10'], ['advance_recovery' => '2000']));

    // Equipment: 8 h × 1,500 = 12,000.
    $assignment = $this->issueEquipment($this->excavator);
    $this->inCompany($this->company, function () use ($assignment, $date) {
        $usage = app(EquipmentUsageService::class);
        $log = $usage->create($this->project, ['equipment_assignment_id' => $assignment->id, 'log_date' => $date, 'opening_meter' => '100', 'closing_meter' => '108']);
        $usage->post($this->project, [$log->id], $this->pm);
    });
});

test('material, labour, subcontract and equipment cost coexist by project and cost head', function () {
    $byHead = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->where('project_id', $this->project->id)
        ->get()->groupBy(fn ($e) => $e->cost_head->value)->map(fn ($rows) => Decimal::sum($rows->pluck('amount')->all())->toMoney())->sortKeys()->all());

    expect($byHead)->toBe([
        'equipment' => '12000.00',
        'labour' => '1340.00',
        'material' => '400.00',
        'subcontract' => '26750.00',
    ])
        ->and($this->costs()->count())->toBe(1 + 2 + 2 + 1)
        ->and(Decimal::sum($this->costs()->pluck('amount')->all())->toMoney())->toBe('40490.00')
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->where('project_id', $this->otherProject->id)->count()))->toBe(0)
        ->and($this->costs()->pluck('boq_line_uid')->unique()->all())->toBe([$this->boqLine->line_uid])
        ->and($this->costs()->every(fn ($e) => $e->is_reversal === false && $e->posting_ref === ''))->toBeTrue();
});

test('every ledger row equals its generating document line, with no duplicates', function () {
    $rows = $this->costs();
    $expected = [
        'material_issue_item' => [CostHead::Material, fn ($id) => MaterialIssueItem::query()->findOrFail($id)->amount],
        'labour_attendance' => [CostHead::Labour, fn ($id) => Decimal::of(($a = LabourAttendance::query()->findOrFail($id))->wage_amount)->plus($a->ot_amount)->toMoney()],
        'subcontractor_bill_item' => [CostHead::Subcontract, fn ($id) => SubcontractorBillItem::query()->findOrFail($id)->amount],
        'equipment_usage_log' => [CostHead::Equipment, fn ($id) => EquipmentUsageLog::query()->findOrFail($id)->cost_amount],
    ];

    $this->inCompany($this->company, function () use ($rows, $expected) {
        foreach ($rows as $row) {
            [$head, $amountOf] = $expected[$row->source_type];
            expect($row->cost_head)->toBe($head)
                ->and($row->amount)->toBe($amountOf($row->source_id));
        }
    });

    $keys = $rows->map(fn ($r) => "{$r->source_type}#{$r->source_id}#{$r->cost_head->value}#{$r->posting_ref}");
    expect($keys->unique()->count())->toBe($rows->count());

    // Document side: every costed document line has exactly one ledger row.
    $this->inCompany($this->company, function () use ($rows) {
        $approved = LabourAttendance::query()->where('approval_status', 'approved')->pluck('id')->sort()->values()->all();
        expect($rows->where('source_type', 'labour_attendance')->pluck('source_id')->sort()->values()->all())->toBe($approved)
            ->and($rows->where('source_type', 'subcontractor_bill_item')->pluck('source_id')->sort()->values()->all())
            ->toBe($this->bill->items()->pluck('id')->sort()->values()->all())
            ->and($rows->where('source_type', 'equipment_usage_log')->pluck('source_id')->all())
            ->toBe(EquipmentUsageLog::query()->whereNotNull('posted_at')->pluck('id')->all());
    });
});

test('labour payments, advances, fuel and repairs never add cost', function () {
    $before = $this->costs()->count();

    $this->inCompany($this->company, function () {
        app(LabourAdvanceService::class)->create($this->project, ['labour_id' => $this->ravi->id, 'advance_date' => now()->toDateString(), 'amount' => '500']);
        $payments = app(LabourPaymentService::class);
        $payment = $payments->create($this->project, ['period_from' => now()->subDays(2)->toDateString(), 'period_to' => now()->toDateString()]);
        $payments->submit($payment, $this->accountant);
        $payments->approve($payment->fresh(), $this->pm);
        $payments->markPaid($payment->fresh(), $this->accountant, ['paid_on' => now()->toDateString()]);

        app(EquipmentFuelService::class)->create($this->project, [
            'equipment_id' => $this->excavator->id, 'log_date' => now()->toDateString(), 'opening_fuel' => '0', 'fuel_added' => '40', 'fuel_consumed' => '30', 'fuel_rate' => '95',
        ]);
        $repair = app(EquipmentRepairService::class)->create($this->project, [
            'equipment_id' => $this->excavator->id, 'repair_date' => now()->toDateString(), 'description' => 'Hose', 'cost' => '2500',
        ]);
        app(EquipmentRepairService::class)->complete($repair, ['completed_date' => now()->toDateString()]);
    });

    expect($this->costs()->count())->toBe($before)
        ->and(Decimal::sum($this->costs()->pluck('amount')->all())->toMoney())->toBe('40490.00')
        ->and($this->netCost(CostHead::Labour))->toBe('1340.00');
});
