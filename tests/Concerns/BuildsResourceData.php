<?php

namespace Tests\Concerns;

use App\Enums\CostHead;
use App\Enums\Equipment\EquipmentOwnership;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\ProjectRole;
use App\Models\Boq\BoqItem;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAttendance;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vendor;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Equipment\EquipmentAssignmentService;
use App\Services\Labour\LabourAttendanceService;
use App\Services\Projects\ProjectService;
use App\Services\Subcontract\SubcontractorBillService;
use App\Services\Subcontract\WorkOrderService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Collection;

/**
 * Phase 6 fixtures on top of the site-execution team: an approved BOQ whose line A.1 is linked to
 * the task, an accountant on the project, two labourers in the Tower A crew (Ravi 800/day, OT 120/h;
 * Sunil 600/day, OT 75/h) and one in Tower B, a subcontractor, an owned excavator billed hourly
 * (1,500/h) and a hired mixer billed daily (2,000/day) from a vendor.
 * Work orders: PM → Director; bills: PM → Director (Director holds certify_bill).
 */
trait BuildsResourceData
{
    use BuildsSiteExecutionData;

    public User $accountant;

    public BoqItem $boqLine;

    public Labour $ravi;

    public Labour $sunil;

    public Labour $outsider;

    public Subcontractor $subcontractor;

    public Vendor $hireVendor;

    public Equipment $excavator;

    public Equipment $hiredMixer;

    public function setUpResources(): void
    {
        $this->setUpSiteExecution();
        $this->accountant = $this->createMember($this->company, DefaultRoles::ACCOUNTANT);
        $this->boqLine = $this->approvedBoqLine();

        $this->inCompany($this->company, function () {
            app(ProjectService::class)->assignMember($this->project, $this->accountant->id, ProjectRole::Accounts);
            $this->task->forceFill(['boq_item_id' => $this->boqLine->id])->save();

            $this->ravi = $this->makeLabour('L-RAVI', 'Ravi Kumar', '800', '120');
            $this->sunil = $this->makeLabour('L-SUNIL', 'Sunil Patil', '600', '75');
            $this->outsider = $this->makeLabour('L-TWB', 'Tower B hand', '700', '90', $this->otherProject->id);

            $this->subcontractor = Subcontractor::query()->create(['code' => 'SUB001', 'name' => 'Shree Formwork']);
            $this->hireVendor = Vendor::query()->create(['code' => 'V-HIRE', 'name' => 'Deccan Equipment Hire', 'state_code' => '27']);
            $this->excavator = $this->makeEquipment('E-JCB', 'Excavator JCB 3DX', EquipmentOwnership::Owned, hourly: '1500');
            $this->hiredMixer = $this->makeEquipment('E-MIX', 'Mixer 10/7', EquipmentOwnership::Hired, daily: '2000', vendorId: $this->hireVendor->id);
        });
    }

    public function makeLabour(string $code, string $name, string $wage, string $ot, ?int $projectId = null): Labour
    {
        return $this->inCompany($this->company, fn () => Labour::query()->create([
            'code' => $code,
            'name' => $name,
            'labour_trade_id' => $this->mason,
            'daily_wage' => $wage,
            'ot_rate_per_hour' => $ot,
            'current_project_id' => $projectId ?? $this->project->id,
            'is_active' => true,
        ]));
    }

    public function makeEquipment(string $code, string $name, EquipmentOwnership $ownership, ?string $hourly = null, ?string $daily = null, ?int $vendorId = null): Equipment
    {
        return $this->inCompany($this->company, fn () => Equipment::query()->create([
            'equipment_type_id' => $this->mixer,
            'code' => $code,
            'name' => $name,
            'ownership' => $ownership,
            'owner_vendor_id' => $vendorId,
            'hourly_rate' => $hourly ?? '0',
            'daily_rate' => $daily ?? '0',
            'status' => EquipmentStatus::Available,
            'is_active' => true,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function mark(array $rows, ?string $date = null, ?User $by = null): void
    {
        $this->inCompany($this->company, fn () => app(LabourAttendanceService::class)->mark($this->project, [
            'attendance_date' => $date ?? now()->toDateString(),
            'site_id' => $this->site->id,
            'rows' => $rows,
        ], $by ?? $this->engineer));
    }

    public function attendanceOf(Labour $labour, ?string $date = null): LabourAttendance
    {
        return $this->inCompany($this->company, fn () => LabourAttendance::query()->where('labour_id', $labour->id)
            ->whereDate('attendance_date', $date ?? now()->toDateString())->firstOrFail());
    }

    /**
     * Mark and approve (PM) one day for the given rows; returns the attendance rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, LabourAttendance>
     */
    public function approvedDay(array $rows, ?string $date = null): Collection
    {
        $date ??= now()->toDateString();
        $this->mark($rows, $date);

        return $this->inCompany($this->company, function () use ($date) {
            $ids = LabourAttendance::query()->where('project_id', $this->project->id)->whereDate('attendance_date', $date)->pluck('id')->all();
            app(LabourAttendanceService::class)->approve($this->project, $ids, $this->pm);

            return LabourAttendance::query()->whereIn('id', $ids)->get();
        });
    }

    /**
     * Two items on BOQ line A.1 / the task: 100 Sqm formwork @ 250 and 20 Cum PCC @ 1,800.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function woPayload(array $overrides = []): array
    {
        $gst18 = $this->inCompany($this->company, fn () => TaxRate::query()->where('name', 'GST 18%')->value('id'));

        return array_replace([
            'subcontractor_id' => $this->subcontractor->id,
            'wo_date' => now()->toDateString(),
            'scope' => 'Formwork and PCC for raft',
            'retention_percent' => '5',
            'advance_amount' => '5000',
            'tax_rate_id' => $gst18,
            'tds_percent' => '1',
            'items' => [
                ['boq_item_id' => $this->boqLine->id, 'task_id' => $this->task->id, 'description' => 'Formwork', 'unit_id' => $this->unitId('Sqm'), 'quantity' => '100', 'rate' => '250'],
                ['boq_item_id' => $this->boqLine->id, 'description' => 'PCC', 'unit_id' => $this->unitId(), 'quantity' => '20', 'rate' => '1800'],
            ],
            'milestones' => [
                ['name' => 'Formwork done', 'amount_percent' => '40'],
                ['name' => 'PCC done', 'amount_percent' => '60'],
            ],
        ], $overrides);
    }

    public function makeWorkOrder(array $overrides = []): WorkOrder
    {
        return $this->inCompany($this->company, fn () => app(WorkOrderService::class)->create($this->project, $this->woPayload($overrides)));
    }

    /**
     * Billing engineer submits → PM → Director.
     */
    public function approveWorkOrder(WorkOrder $order): WorkOrder
    {
        return $this->inCompany($this->company, function () use ($order) {
            app(WorkOrderService::class)->submit($order->fresh(), $this->billing);
            $approvals = app(ApprovalService::class);
            $request = $approvals->approve($order->fresh()->pendingApprovalRequest(), $this->pm);
            $approvals->approve($request, $this->director);

            return $order->fresh();
        });
    }

    /**
     * @param  list<string|null>  $claimed  per WO line in order
     */
    public function makeBill(WorkOrder $order, array $claimed, array $extra = []): SubcontractorBill
    {
        return $this->inCompany($this->company, function () use ($order, $claimed, $extra) {
            $lines = $order->items()->orderBy('id')->get()->values();

            return app(SubcontractorBillService::class)->create($this->project, $extra + [
                'work_order_id' => $order->id,
                'bill_date' => now()->toDateString(),
                'period_from' => now()->subDays(7)->toDateString(),
                'period_to' => now()->toDateString(),
                'items' => $lines->map(fn ($l, $i) => ['work_order_item_id' => $l->id, 'claimed_qty' => $claimed[$i] ?? null])->all(),
            ]);
        });
    }

    /**
     * Billing engineer submits → PM → Director (the final approval certifies).
     */
    public function certifyBill(SubcontractorBill $bill): SubcontractorBill
    {
        return $this->inCompany($this->company, function () use ($bill) {
            app(SubcontractorBillService::class)->submit($bill->fresh(), $this->billing);
            $approvals = app(ApprovalService::class);
            $request = $approvals->approve($bill->fresh()->pendingApprovalRequest(), $this->pm);
            $approvals->approve($request, $this->director);

            return $bill->fresh();
        });
    }

    public function issueEquipment(Equipment $equipment, string $basis = 'hourly', array $extra = []): EquipmentAssignment
    {
        return $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->issue($this->project, $extra + [
            'equipment_id' => $equipment->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'rate_basis' => $basis,
            'site_id' => $this->site->id,
            'task_id' => $this->task->id,
        ]));
    }

    /**
     * @return Collection<int, ProjectCostEntry>
     */
    public function costs(?CostHead $head = null): Collection
    {
        return $this->inCompany($this->company, fn () => ProjectCostEntry::query()
            ->when($head, fn ($q) => $q->where('cost_head', $head))->orderBy('id')->get());
    }

    public function netCost(CostHead $head): string
    {
        return Decimal::sum($this->costs($head)->pluck('amount')->all())->toMoney();
    }
}
