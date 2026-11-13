<?php

namespace App\Services\Gate;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassItemType;
use App\Enums\Gate\GatePassStatus;
use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Models\Equipment\Equipment;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassItem;
use App\Models\Masters\Material;
use App\Models\Masters\ScrapType;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Equipment\EquipmentLocationService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Scrap\ScrapBalanceService;
use App\Services\Scrap\ScrapLedgerService;
use App\Services\Scrap\ScrapMovement;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use App\Support\Math\Decimal;
use App\Support\Transitions\DocumentTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gate movement register. It does not post stock. An equipment line updates the machine's
 * location only when the pass is gate-verified.
 *
 * Dual approval path (R2): when `ApprovalService::resolveWorkflow` finds an active `gate_pass`
 * workflow, submit/approve/reject use the approval engine. Otherwise legacy `transition()` is
 * used (Draft/Rejected → Submitted → Approved/Rejected). Gate verify is never approval-backed;
 * scrap `gate_pass_out` posts only on verify and is ledger-idempotent. Do not introduce a
 * mandatory workflow where none is configured.
 */
class GatePassService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly WeighbridgeReceiptService $weighbridge,
        private readonly EquipmentLocationService $locations,
        private readonly ApprovalService $approvals,
        private readonly ScrapLedgerService $scrapLedger,
        private readonly ScrapBalanceService $scrapBalances,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): GatePass
    {
        return DB::transaction(function () use ($project, $data) {
            $pass = new GatePass;
            $pass->forceFill([
                ...$this->header($project, $data),
                'project_id' => $project->id,
                'pass_number' => $this->numbers->next('gate_pass', $project),
                'status' => GatePassStatus::Draft,
            ])->save();
            $this->replaceItems($pass, $data['items'] ?? []);
            $this->weighbridge->sync($pass, $data['weighbridge'] ?? null);

            return $pass;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GatePass $pass, array $data): GatePass
    {
        return DB::transaction(function () use ($pass, $data) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            $pass->assertEditable();
            $pass->forceFill($this->header($pass->project, $data))->save();
            $this->replaceItems($pass, $data['items'] ?? []);
            $this->weighbridge->sync($pass, $data['weighbridge'] ?? null);

            return $pass;
        });
    }

    public function delete(GatePass $pass): void
    {
        $pass->assertEditable();
        $pass->items()->delete();
        $pass->weighbridgeReceipt()?->delete();
        $pass->delete();
    }

    public function submit(GatePass $pass, User $user): void
    {
        DB::transaction(function () use ($pass, $user) {
            $locked = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if ($locked->items()->doesntExist()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line.']);
            }

            if ($this->approvals->resolveWorkflow($locked) !== null) {
                $this->approvals->submit($locked, $user);
            } else {
                $this->transition($locked, [GatePassStatus::Draft, GatePassStatus::Rejected], GatePassStatus::Submitted);
            }
        });
    }

    public function approve(GatePass $pass, User $user, ?string $comments = null): void
    {
        $pending = $pass->pendingApprovalRequest();
        if ($pending !== null) {
            $this->approvals->approve($pending, $user, $comments ?? 'Approved.');

            return;
        }

        $this->transition($pass, [GatePassStatus::Submitted], GatePassStatus::Approved, [
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(GatePass $pass, User $user, string $reason): void
    {
        $pending = $pass->pendingApprovalRequest();
        if ($pending !== null) {
            $this->approvals->reject($pending, $user, $reason);

            return;
        }

        $this->transition($pass, [GatePassStatus::Submitted], GatePassStatus::Rejected, [
            'rejected_reason' => $reason,
        ]);
    }

    /** Final approval (inside the approval engine's transaction). */
    public function markApproved(GatePass $pass, ?int $approverId): void
    {
        DB::transaction(function () use ($pass, $approverId) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            if ($pass->status === GatePassStatus::Approved) {
                return;
            }

            $pass->forceFill([
                'status' => GatePassStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
        });
    }

    public function verify(GatePass $pass, User $user): void
    {
        DB::transaction(function () use ($pass, $user) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();

            // Idempotent retry: scrap ledger is keyed by gate_pass_item + gate_pass_out.
            if (in_array($pass->status, [GatePassStatus::Verified, GatePassStatus::Closed], true)) {
                $this->postScrapOutbound($pass, $user);

                return;
            }

            if ($pass->status !== GatePassStatus::Approved) {
                throw ValidationException::withMessages(['gate_pass' => 'Only an approved gate pass can be verified at the gate.']);
            }

            if ($pass->direction === GatePassDirection::Out) {
                $this->assertScrapAvailable($pass);
            }

            $pass->forceFill([
                'status' => GatePassStatus::Verified,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ])->save();
            $this->moveEquipment($pass, $user);
            $this->postScrapOutbound($pass, $user);
            if (! $pass->returnable) {
                $pass->forceFill(['status' => GatePassStatus::Closed])->save();
            }
        });
    }

    public function markReturned(GatePass $pass, User $user): void
    {
        // Compatibility path: record remaining qty as a line-wise return (and verify when allowed).
        $pass->loadMissing('items');
        app(GatePassReturnService::class)->markFullyReturned($pass, $user);
    }

    /**
     * @param  list<GatePassStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function transition(GatePass $pass, array $from, GatePassStatus $to, array $extra = []): void
    {
        DB::transaction(function () use ($pass, $from, $to, $extra) {
            $pass = DocumentTransition::lock($pass);
            DocumentTransition::assertStatus(
                $pass,
                $from,
                'gate_pass',
                'This gate pass cannot move to '.$to->label().' from '.$pass->status->label().'.',
            );
            if ($pass->items()->doesntExist()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line.']);
            }
            DocumentTransition::apply($pass, $to, $extra);
        });
    }

    private function moveEquipment(GatePass $pass, User $user): void
    {
        $outbound = $pass->direction === GatePassDirection::Out;
        foreach ($pass->items()->whereNotNull('equipment_id')->get() as $item) {
            $equipment = Equipment::query()->find($item->equipment_id);
            if ($equipment === null) {
                continue;
            }
            $this->locations->change($equipment, [
                'location_type' => $outbound ? EquipmentLocationType::InTransit->value : EquipmentLocationType::ProjectSite->value,
                'location_project_id' => $outbound ? null : $pass->project_id,
                'location_name' => $outbound ? ($item->destination ?: $pass->party_name ?: $pass->vehicle_no) : $pass->project?->name,
                'remarks' => "Gate pass {$pass->pass_number}",
            ], $user);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Project $project, array $data): array
    {
        $returnable = (bool) ($data['returnable'] ?? false);
        if ($returnable && blank($data['expected_return_date'] ?? null)) {
            throw ValidationException::withMessages(['expected_return_date' => 'A returnable pass needs an expected return date.']);
        }

        return [
            'site_id' => $data['site_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'direction' => $data['direction'],
            'returnable' => $returnable,
            'passed_at' => $data['passed_at'],
            'expected_return_date' => $returnable ? $data['expected_return_date'] : null,
            'party_name' => $data['party_name'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'driver_mobile' => $data['driver_mobile'] ?? null,
            'transporter_name' => $data['transporter_name'] ?? null,
            'transporter_contact' => $data['transporter_contact'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_contact' => $data['recipient_contact'] ?? null,
            'purpose' => $data['purpose'],
            'permanent_removal_reason' => $returnable ? null : ($data['permanent_removal_reason'] ?? null),
            'dispatch_notes' => $data['dispatch_notes'] ?? null,
            'closure_notes' => $data['closure_notes'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(GatePass $pass, array $rows): void
    {
        $pass->items()->delete();
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }
        foreach (array_values($rows) as $index => $row) {
            $type = GatePassItemType::tryFrom((string) ($row['item_type'] ?? ''))
                ?? throw ValidationException::withMessages(["items.{$index}.item_type" => 'Choose the kind of item.']);
            $qty = Decimal::of((string) ($row['quantity'] ?? '0'));
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be greater than zero.']);
            }
            $scrapType = null;
            $scrapTypeId = blank($row['scrap_type_id'] ?? null) ? null : (int) $row['scrap_type_id'];
            if ($type === GatePassItemType::Scrap) {
                if ($scrapTypeId === null) {
                    throw ValidationException::withMessages(["items.{$index}.scrap_type_id" => 'Choose the scrap type.']);
                }
                $scrapType = ScrapType::query()->active()->whereKey($scrapTypeId)->first()
                    ?? throw ValidationException::withMessages(["items.{$index}.scrap_type_id" => 'Choose a scrap type of this company.']);
            }
            $materialId = blank($row['material_id'] ?? null) ? null : (int) $row['material_id'];
            if ($materialId !== null && Material::query()->whereKey($materialId)->doesntExist()) {
                throw ValidationException::withMessages(["items.{$index}.material_id" => 'Choose a material of this company.']);
            }
            $equipmentId = blank($row['equipment_id'] ?? null) ? null : (int) $row['equipment_id'];
            if ($type === GatePassItemType::Equipment && $equipmentId === null) {
                throw ValidationException::withMessages(["items.{$index}.equipment_id" => 'Choose the equipment.']);
            }
            if ($equipmentId !== null && Equipment::query()->whereKey($equipmentId)->doesntExist()) {
                throw ValidationException::withMessages(["items.{$index}.equipment_id" => 'Choose equipment of this company.']);
            }

            $unitId = blank($row['unit_id'] ?? null) ? null : (int) $row['unit_id'];
            if ($scrapType !== null) {
                $unitId = $unitId ?? $scrapType->unit_id;
                if ($unitId === null) {
                    throw ValidationException::withMessages(["items.{$index}.unit_id" => 'This scrap type needs a default unit.']);
                }
                if ($scrapType->unit_id !== null && (int) $unitId !== (int) $scrapType->unit_id) {
                    throw ValidationException::withMessages(["items.{$index}.unit_id" => 'Use the unit defined for this scrap type.']);
                }
                if ($pass->direction === GatePassDirection::Out) {
                    $available = $this->scrapBalances->availableQty($pass->project_id, $scrapType->id, $unitId);
                    if ($qty->greaterThan($available)) {
                        throw ValidationException::withMessages(["items.{$index}.quantity" => "Only {$available->toQuantity()} {$scrapType->name} available on this project."]);
                    }
                }
            }

            (new GatePassItem)->forceFill([
                'gate_pass_id' => $pass->id,
                'item_type' => $type,
                'material_id' => $materialId,
                'equipment_id' => $equipmentId,
                'description' => $row['description'],
                'quantity' => $qty->toQuantity(),
                'unit_id' => $unitId,
                'weight' => blank($row['weight'] ?? null) ? null : Decimal::of((string) $row['weight'])->toQuantity(),
                'scrap_type_id' => $scrapType?->id,
                'scrap_type' => $scrapType?->name ?? ($row['scrap_type'] ?? null),
                'rate' => blank($row['rate'] ?? null) ? null : Decimal::of((string) $row['rate'])->toRate(),
                'destination' => $row['destination'] ?? null,
                'remarks' => $row['remarks'] ?? null,
                'sort_order' => $index + 1,
            ])->save();
        }
    }

    private function assertScrapAvailable(GatePass $pass): void
    {
        foreach ($pass->items()->where('item_type', GatePassItemType::Scrap)->whereNotNull('scrap_type_id')->get() as $item) {
            $available = $this->scrapBalances->availableQty($pass->project_id, (int) $item->scrap_type_id, (int) $item->unit_id);
            $required = Decimal::of($item->quantity);
            if ($required->greaterThan($available)) {
                $name = $item->scrap_type ?: 'scrap';
                throw ValidationException::withMessages(['items' => "Insufficient scrap of {$name}: available {$available->toQuantity()}, required {$required->toQuantity()}."]);
            }
        }
    }

    private function postScrapOutbound(GatePass $pass, User $user): void
    {
        if ($pass->direction !== GatePassDirection::Out) {
            return;
        }

        $project = Project::query()->findOrFail($pass->project_id);
        foreach ($pass->items()->where('item_type', GatePassItemType::Scrap)->whereNotNull('scrap_type_id')->get() as $item) {
            $this->scrapLedger->post(new ScrapMovement(
                reference: $item,
                type: ScrapLedgerTxnType::GatePassOut,
                project: $project,
                scrapTypeId: (int) $item->scrap_type_id,
                unitId: (int) $item->unit_id,
                quantity: Decimal::of($item->quantity),
                date: $pass->passed_at,
                referenceNumber: $pass->pass_number,
                remarks: $pass->pass_number,
                userId: $user->id,
                errorKey: 'gate_pass',
            ));
        }
    }
}
