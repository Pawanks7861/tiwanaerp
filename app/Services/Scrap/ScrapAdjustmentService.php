<?php

namespace App\Services\Scrap;

use App\Enums\Scrap\ScrapAdjustmentStatus;
use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Models\Masters\ScrapType;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapAdjustment;
use App\Models\Scrap\ScrapAdjustmentItem;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scrap quantity adjustments (in/out). Ledger posts only from `post()` when Submitted → Approved.
 *
 * Dual path (R2): active `scrap_adjustment` workflow → ApprovalService; otherwise legacy
 * submit/approve. Reject/send-back/withdraw → Draft (no Rejected enum).
 *
 * R16: `ScrapAdjustmentStatus::Cancelled` exists on the enum, but this service has **no**
 * `cancel()` method and the table has no cancellation columns. Do not invent a cancel/reversal
 * workflow here without a product decision and safe ledger reversal design.
 */
class ScrapAdjustmentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ScrapLedgerService $ledger,
        private readonly ScrapBalanceService $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): ScrapAdjustment
    {
        return DB::transaction(function () use ($project, $data) {
            $adjustment = new ScrapAdjustment($this->header($data));
            $adjustment->forceFill([
                'project_id' => $project->id,
                'adjustment_number' => $this->numbers->next('scrap_adjustment', $project),
                'status' => ScrapAdjustmentStatus::Draft,
            ])->save();

            $this->replaceItems($adjustment, $project, $data['items'] ?? []);

            return $adjustment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ScrapAdjustment $adjustment, array $data): ScrapAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data) {
            $adjustment = ScrapAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $adjustment->assertEditable();
            $project = Project::query()->findOrFail($adjustment->project_id);
            $adjustment->fill($this->header($data))->save();
            $this->replaceItems($adjustment, $project, $data['items'] ?? []);

            return $adjustment;
        });
    }

    public function delete(ScrapAdjustment $adjustment): void
    {
        $adjustment->assertEditable();
        $adjustment->items()->delete();
        $adjustment->delete();
    }

    public function submit(ScrapAdjustment $adjustment, User $user): void
    {
        DB::transaction(function () use ($adjustment, $user) {
            $locked = ScrapAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if ($locked->items()->doesntExist()) {
                throw ValidationException::withMessages(['items' => 'Add at least one line before submitting.']);
            }

            if ($locked->direction === 'out') {
                $this->assertAvailable($locked);
            }

            if ($this->approvals->resolveWorkflow($locked) !== null) {
                $this->approvals->submit($locked, $user);
            } else {
                $locked->forceFill(['status' => ScrapAdjustmentStatus::Submitted])->save();
            }

            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function approve(ScrapAdjustment $adjustment, User $user, ?string $comments = null): void
    {
        $pending = $adjustment->pendingApprovalRequest();
        if ($pending !== null) {
            $this->approvals->approve($pending, $user, $comments ?? 'Approved.');

            return;
        }

        $this->post($adjustment, $user->id);
    }

    public function post(ScrapAdjustment $adjustment, ?int $approverId): void
    {
        DB::transaction(function () use ($adjustment, $approverId) {
            $locked = ScrapAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ScrapAdjustmentStatus::Approved) {
                return;
            }
            if ($locked->status !== ScrapAdjustmentStatus::Submitted) {
                throw ValidationException::withMessages(['scrap_adjustment' => 'Only a submitted adjustment can be approved.']);
            }

            $project = Project::query()->findOrFail($locked->project_id);
            $type = $locked->direction === 'in' ? ScrapLedgerTxnType::AdjustmentIn : ScrapLedgerTxnType::AdjustmentOut;

            if ($type === ScrapLedgerTxnType::AdjustmentOut) {
                $this->assertAvailable($locked);
            }

            foreach ($locked->items()->reorder('scrap_type_id')->orderBy('id')->get() as $item) {
                $this->ledger->post(new ScrapMovement(
                    reference: $item,
                    type: $type,
                    project: $project,
                    scrapTypeId: $item->scrap_type_id,
                    unitId: $item->unit_id,
                    quantity: Decimal::of($item->quantity),
                    date: $locked->adjustment_date,
                    referenceNumber: $locked->adjustment_number,
                    remarks: $locked->reason,
                    userId: $approverId,
                    errorKey: 'scrap_adjustment',
                ));
            }

            $locked->forceFill([
                'status' => ScrapAdjustmentStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
            $adjustment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $direction = strtolower((string) ($data['direction'] ?? ''));
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['direction' => 'Direction must be in or out.']);
        }
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the adjustment.']);
        }

        return [
            'adjustment_date' => $data['adjustment_date'],
            'direction' => $direction,
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(ScrapAdjustment $adjustment, Project $project, array $rows): void
    {
        ScrapAdjustmentItem::query()->where('scrap_adjustment_id', $adjustment->id)->delete();

        if ($rows === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        foreach (array_values($rows) as $index => $row) {
            $scrapType = ScrapType::query()->active()->whereKey((int) ($row['scrap_type_id'] ?? 0))->first()
                ?? throw ValidationException::withMessages(["items.{$index}.scrap_type_id" => 'Choose an active scrap type.']);

            $qty = Decimal::of((string) ($row['quantity'] ?? '0'));
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Quantity must be greater than zero.']);
            }

            $unitId = blank($row['unit_id'] ?? null) ? $scrapType->unit_id : (int) $row['unit_id'];
            if ($unitId === null) {
                throw ValidationException::withMessages(["items.{$index}.unit_id" => 'This scrap type needs a default unit in masters.']);
            }

            (new ScrapAdjustmentItem)->forceFill([
                'scrap_adjustment_id' => $adjustment->id,
                'scrap_type_id' => $scrapType->id,
                'quantity' => $qty->toQuantity(),
                'unit_id' => $unitId,
                'remarks' => $row['remarks'] ?? null,
            ])->save();
        }
    }

    private function assertAvailable(ScrapAdjustment $adjustment): void
    {
        $projectId = $adjustment->project_id;
        foreach ($adjustment->items as $item) {
            $available = $this->balances->availableQty($projectId, $item->scrap_type_id, $item->unit_id);
            $required = Decimal::of($item->quantity);
            if ($required->greaterThan($available)) {
                $name = $item->scrapType?->name ?? 'scrap';
                throw ValidationException::withMessages([
                    'items' => "Insufficient scrap of {$name}: available {$available->toQuantity()}, required {$required->toQuantity()}.",
                ]);
            }
        }
    }
}
