<?php

namespace App\Services\Labour;

use App\Enums\Labour\LabourPaymentStatus;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourPaymentLine;
use App\Models\Projects\Project;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Labour advances are receivables, not project cost. What has been recovered is derived, never
 * accumulated: the advance_recovery of the labourer's approved / paid payment lines, allocated
 * oldest advance first (recompute()). Draft / submitted batches only reserve recovery.
 */
class LabourAdvanceService
{
    use ResolvesProjectRefs;

    /**
     * @param  array<string, mixed>  $data  labour_id, advance_date, amount, remarks
     */
    public function create(Project $project, array $data): LabourAdvance
    {
        return DB::transaction(function () use ($project, $data) {
            $labour = Labour::query()->active()->whereKey((int) ($data['labour_id'] ?? 0))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['labour_id' => 'Choose an active labourer of this company.']);

            $amount = $this->amount($data['amount'] ?? null, 'amount', required: true);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'The advance must be greater than zero.']);
            }

            $advance = new LabourAdvance;
            $advance->forceFill([
                'labour_id' => $labour->id,
                'project_id' => $project->id,
                'advance_date' => $data['advance_date'],
                'amount' => $amount->toMoney(),
                'recovered_amount' => '0.00',
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $this->recompute($labour->id);

            return $advance->refresh();
        });
    }

    public function delete(LabourAdvance $advance): void
    {
        DB::transaction(function () use ($advance) {
            Labour::query()->withTrashed()->whereKey($advance->labour_id)->lockForUpdate()->first();
            $locked = LabourAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if (! Decimal::of($locked->recovered_amount)->isZero()) {
                throw ValidationException::withMessages(['advance' => 'Part of this advance has been recovered; it cannot be deleted.']);
            }

            $remaining = $this->outstanding($locked->labour_id)->minus($locked->amount);
            if ($this->pending($locked->labour_id)->greaterThan($remaining)) {
                throw ValidationException::withMessages(['advance' => 'A pending labour payment recovers this advance. Change that payment first.']);
            }

            $locked->delete();
            $this->recompute($locked->labour_id);
        });
    }

    /** Advanced minus recovered by settled (approved / paid) payments. */
    public function outstanding(int $labourId): Decimal
    {
        $advanced = Decimal::sum(LabourAdvance::query()->where('labour_id', $labourId)->pluck('amount')->all());

        return $advanced->minus($this->settled($labourId));
    }

    /** Recovery reserved by draft / submitted payments (optionally excluding one). */
    public function pending(int $labourId, ?int $exceptPaymentId = null): Decimal
    {
        return Decimal::sum(
            LabourPaymentLine::query()
                ->where('labour_id', $labourId)
                ->when($exceptPaymentId, fn ($q) => $q->where('labour_payment_id', '!=', $exceptPaymentId))
                ->whereHas('payment', fn ($q) => $q->whereIn('status', [LabourPaymentStatus::Draft, LabourPaymentStatus::Submitted]))
                ->pluck('advance_recovery')->all()
        );
    }

    /** What a payment may still recover from the labourer. */
    public function recoverable(int $labourId, ?int $exceptPaymentId = null): Decimal
    {
        return $this->outstanding($labourId)->minus($this->pending($labourId, $exceptPaymentId));
    }

    /**
     * Rebuild recovered_amount of every advance of the labourer from settled payment lines,
     * oldest advance first. Deterministic: running it twice changes nothing.
     */
    public function recompute(int $labourId): void
    {
        $remaining = $this->settled($labourId);
        $advances = LabourAdvance::query()->where('labour_id', $labourId)
            ->orderBy('advance_date')->orderBy('id')->lockForUpdate()->get();

        foreach ($advances as $advance) {
            $recovered = $remaining->greaterThan($advance->amount) ? Decimal::of($advance->amount) : $remaining;
            $remaining = $remaining->minus($recovered);

            if (! Decimal::of($advance->recovered_amount)->equals($recovered)) {
                $advance->forceFill(['recovered_amount' => $recovered->toMoney()])->save();
            }
        }

        if ($remaining->isPositive()) {
            throw new LogicException("Labour {$labourId}: recoveries exceed advances by {$remaining->toMoney()}.");
        }
    }

    private function settled(int $labourId): Decimal
    {
        return Decimal::sum(
            LabourPaymentLine::query()
                ->where('labour_id', $labourId)
                ->whereHas('payment', fn ($q) => $q->whereIn('status', LabourPaymentStatus::settled()))
                ->pluck('advance_recovery')->all()
        );
    }
}
