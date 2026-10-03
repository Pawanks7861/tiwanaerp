<?php

namespace App\Services\Finance;

use App\Enums\Finance\RetentionReleaseStatus;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\RetentionRelease;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retention releases on certified client RA bills and certified subcontractor bills:
 * draft → submitted (engine: PM → Director) → approved.
 *
 * release ≤ retention held on the bill − released by other approved / submitted releases.
 * Approval only makes the released amount due again on that bill (its outstanding grows); the
 * cash moves through a receipt (client) or payment (subcontractor) allocated to the same bill.
 * Nothing here touches the project cost ledger.
 */
class RetentionReleaseService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly PayableService $payables,
    ) {}

    /**
     * @param  array<string, mixed>  $data  releasable_type, releasable_id, release_date, amount, remarks
     */
    public function create(Project $project, array $data, User $user): RetentionRelease
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $bill = $this->bill($project, (string) ($data['releasable_type'] ?? ''), $data['releasable_id'] ?? null);
            $this->assertCanCreate($user, $bill);
            $amount = $this->releaseAmount($data['amount'] ?? null);
            $this->assertWithinHeld($bill, $amount, null, includePending: true);

            $release = new RetentionRelease;
            $release->forceFill([
                'project_id' => $project->id,
                'release_number' => $this->numbers->next('retention_release', $project),
                'releasable_type' => $bill->getMorphClass(),
                'releasable_id' => $bill->getKey(),
                'release_date' => $data['release_date'],
                'amount' => $amount->toMoney(),
                'remarks' => $data['remarks'] ?? null,
                'status' => RetentionReleaseStatus::Draft,
            ])->save();

            return $release;
        });
    }

    /**
     * @param  array<string, mixed>  $data  release_date, amount, remarks
     */
    public function update(RetentionRelease $release, array $data, User $user): RetentionRelease
    {
        return DB::transaction(function () use ($release, $data, $user) {
            $locked = RetentionRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $bill = $this->lockBill($locked);
            $this->assertCanCreate($user, $bill);
            $amount = $this->releaseAmount($data['amount'] ?? null);
            $this->assertWithinHeld($bill, $amount, $locked->id, includePending: true);

            $locked->forceFill([
                'release_date' => $data['release_date'],
                'amount' => $amount->toMoney(),
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            return $locked;
        });
    }

    public function delete(RetentionRelease $release): void
    {
        DB::transaction(function () use ($release) {
            $locked = RetentionRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->delete();
        });
    }

    public function submit(RetentionRelease $release, User $user): void
    {
        DB::transaction(function () use ($release, $user) {
            $locked = RetentionRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $bill = $this->lockBill($locked);
            $this->assertWithinHeld($bill, Decimal::of($locked->amount), $locked->id, includePending: true);

            $this->approvals->submit($locked, $user);
            $release->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the engine's transaction); the approver needs billing.certify (client
     * retention) or subcontract.certify_bill (subcontractor retention). Idempotent.
     */
    public function approve(RetentionRelease $release, ?int $approverId): void
    {
        DB::transaction(function () use ($release, $approverId) {
            $locked = RetentionRelease::query()->whereKey($release->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === RetentionReleaseStatus::Approved) {
                return;
            }
            if ($locked->status !== RetentionReleaseStatus::Submitted) {
                throw ValidationException::withMessages(['release' => 'Only a submitted retention release can be approved.']);
            }
            $bill = $this->lockBill($locked);
            $permission = $bill instanceof ClientInvoice ? 'billing.certify' : 'subcontract.certify_bill';
            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, $permission)) {
                throw ValidationException::withMessages(['approval' => "Approving this retention release needs the {$permission} permission."]);
            }
            $this->assertWithinHeld($bill, Decimal::of($locked->amount), $locked->id, includePending: false);

            $locked->forceFill([
                'status' => RetentionReleaseStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
            $this->payables->refresh($bill);
            $release->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Retention still held on a bill (held − approved releases). */
    public function balance(Model $bill, bool $includePending = false, ?int $exceptReleaseId = null): Decimal
    {
        return Decimal::of($bill->retention_amount)->minus($this->payables->releasedRetention($bill, $includePending, $exceptReleaseId));
    }

    private function bill(Project $project, string $type, mixed $id): Model
    {
        $class = match ($type) {
            'client_invoice' => ClientInvoice::class,
            'subcontractor_bill' => SubcontractorBill::class,
            default => throw ValidationException::withMessages(['releasable_type' => 'Choose a client RA bill or a subcontractor bill.']),
        };
        $bill = is_numeric($id) ? $class::query()->where('project_id', $project->id)->whereKey((int) $id)->lockForUpdate()->first() : null;
        if ($bill === null || ! $bill->status->isCertified()) {
            throw ValidationException::withMessages(['releasable_id' => 'Choose a certified bill of this project.']);
        }

        return $bill;
    }

    private function lockBill(RetentionRelease $release): Model
    {
        $class = $this->payables->classFor($release->releasable_type);

        return $class::query()->whereKey($release->releasable_id)->lockForUpdate()->firstOrFail();
    }

    private function assertCanCreate(User $user, Model $bill): void
    {
        $permission = $bill instanceof ClientInvoice ? 'billing.create' : 'subcontract.create';
        if (! CompanyPermission::check($user, (int) $bill->company_id, $permission)) {
            throw ValidationException::withMessages(['releasable_id' => "Releasing this retention needs the {$permission} permission."]);
        }
    }

    private function releaseAmount(mixed $value): Decimal
    {
        $amount = $this->amount($value, 'amount', required: true);
        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        return $amount;
    }

    private function assertWithinHeld(Model $bill, Decimal $amount, ?int $exceptReleaseId, bool $includePending): void
    {
        $balance = $this->balance($bill, $includePending, $exceptReleaseId);
        if ($amount->greaterThan($balance)) {
            throw ValidationException::withMessages(['amount' => "Only {$balance->toMoney()} of retention (held {$bill->retention_amount}) is left to release on this bill."]);
        }
    }
}
