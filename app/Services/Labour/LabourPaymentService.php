<?php

namespace App\Services\Labour;

use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Labour\LabourPaymentLine;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Labour payment batches: draft → submitted → approved → paid. A batch takes the approved
 * attendance of its period that is not yet in another batch (the attendance row's
 * labour_payment_id is the double-payment guard). Net = gross + OT − advance recovery − other
 * deductions. Paying settles the wage liability; the labour cost was posted at attendance
 * approval, so nothing here writes the project cost ledger.
 */
class LabourPaymentService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly LabourAdvanceService $advances,
    ) {}

    /**
     * @param  array<string, mixed>  $data  period_from, period_to, remarks
     */
    public function create(Project $project, array $data): LabourPayment
    {
        $from = CarbonImmutable::parse($data['period_from'])->toDateString();
        $to = CarbonImmutable::parse($data['period_to'])->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['period_to' => 'The period must end on or after its start.']);
        }

        return DB::transaction(function () use ($project, $data, $from, $to) {
            $rows = LabourAttendance::query()
                ->where('project_id', $project->id)
                ->where('approval_status', AttendanceApproval::Approved)
                ->whereNull('labour_payment_id')
                ->whereDate('attendance_date', '>=', $from)
                ->whereDate('attendance_date', '<=', $to)
                ->orderBy('labour_id')->orderBy('attendance_date')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['period_from' => 'There is no approved, unpaid attendance in this period.']);
            }

            $payment = new LabourPayment;
            $payment->forceFill([
                'project_id' => $project->id,
                'payment_number' => $this->numbers->next('labour_payment', $project),
                'period_from' => $from,
                'period_to' => $to,
                'status' => LabourPaymentStatus::Draft,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            LabourAttendance::query()->whereIn('id', $rows->modelKeys())->update(['labour_payment_id' => $payment->id]);

            foreach ($rows->groupBy('labour_id') as $labourId => $days) {
                $line = (new LabourPaymentLine)->forceFill([
                    'labour_payment_id' => $payment->id,
                    'labour_id' => $labourId,
                    ...$this->lineTotals($days),
                    'advance_recovery' => '0.00',
                    'other_deductions' => '0.00',
                ]);
                $line->forceFill(['net_amount' => $this->net($line)->toMoney()])->save();
            }

            $this->refreshTotals($payment);

            return $payment;
        });
    }

    /**
     * Enter advance recovery / other deductions per line of a draft batch.
     *
     * @param  list<array<string, mixed>>  $lines  id, advance_recovery, other_deductions, remarks
     */
    public function updateLines(LabourPayment $payment, array $lines, ?string $remarks = null): LabourPayment
    {
        return DB::transaction(function () use ($payment, $lines, $remarks) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $existing = $locked->lines()->get()->keyBy('id');
            foreach (array_values($lines) as $index => $row) {
                $line = $existing->get((int) ($row['id'] ?? 0))
                    ?? throw ValidationException::withMessages(["lines.{$index}.id" => 'This line does not belong to the payment.']);

                Labour::query()->withTrashed()->whereKey($line->labour_id)->lockForUpdate()->first();
                $recovery = $this->amount($row['advance_recovery'] ?? null, "lines.{$index}.advance_recovery");
                $other = $this->amount($row['other_deductions'] ?? null, "lines.{$index}.other_deductions");

                $this->assertDeductions($line, $recovery, $other, $this->advances->recoverable($line->labour_id, $locked->id), "lines.{$index}");

                $line->forceFill([
                    'advance_recovery' => $recovery->toMoney(),
                    'other_deductions' => $other->toMoney(),
                    'remarks' => $row['remarks'] ?? $line->remarks,
                ])->save();
                $line->forceFill(['net_amount' => $this->net($line)->toMoney()])->save();
            }

            if ($remarks !== null) {
                $locked->forceFill(['remarks' => $remarks])->save();
            }
            $this->refreshTotals($locked);
            $payment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /** Delete a draft batch and release its attendance for another batch. */
    public function delete(LabourPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            LabourAttendance::query()->where('labour_payment_id', $locked->id)->update(['labour_payment_id' => null]);
            $locked->lines()->get()->each->delete();
            $locked->delete();
        });
    }

    public function submit(LabourPayment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $this->assertRecoveries($locked, settledOnly: false);

            $locked->forceFill([
                'status' => LabourPaymentStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'return_reason' => null,
            ])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Maker-checker approval; the advances' recovered amounts are rebuilt from settled lines. */
    public function approve(LabourPayment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LabourPaymentStatus::Submitted) {
                throw ValidationException::withMessages(['payment' => 'Only a submitted payment can be approved.']);
            }
            if ((int) $locked->submitted_by === (int) $user->id) {
                throw ValidationException::withMessages(['payment' => 'A payment cannot be approved by the person who submitted it.']);
            }
            $this->assertRecoveries($locked, settledOnly: true);

            $locked->forceFill([
                'status' => LabourPaymentStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            foreach ($locked->lines()->pluck('labour_id')->unique() as $labourId) {
                $this->advances->recompute((int) $labourId);
            }
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function sendBack(LabourPayment $payment, User $user, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LabourPaymentStatus::Submitted) {
                throw ValidationException::withMessages(['payment' => 'Only a submitted payment can be returned.']);
            }

            $locked->forceFill([
                'status' => LabourPaymentStatus::Draft,
                'submitted_by' => null,
                'submitted_at' => null,
                'return_reason' => $reason,
            ])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Record the payout of an approved batch (date and reference only: cash / bank accounting is
     * Phase 7). No cost posting: the cost was posted at attendance approval.
     *
     * @param  array<string, mixed>  $data  paid_on, payment_reference
     */
    public function markPaid(LabourPayment $payment, User $user, array $data): void
    {
        DB::transaction(function () use ($payment, $user, $data) {
            $locked = LabourPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LabourPaymentStatus::Approved) {
                throw ValidationException::withMessages(['payment' => 'Only an approved payment can be marked paid.']);
            }
            if (Decimal::of($locked->paid_amount)->isPositive()) {
                throw ValidationException::withMessages(['payment' => 'This batch is being settled through Finance payments; record the balance there.']);
            }

            $locked->forceFill([
                'status' => LabourPaymentStatus::Paid,
                'paid_by' => $user->id,
                'paid_at' => now(),
                'paid_on' => $data['paid_on'],
                'payment_reference' => $data['payment_reference'] ?? null,
            ])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Other batches of the project whose period overlaps (information: attendance itself can
     * never be in two batches).
     *
     * @return Collection<int, LabourPayment>
     */
    public function overlapping(LabourPayment $payment): Collection
    {
        return LabourPayment::query()
            ->where('project_id', $payment->project_id)
            ->whereKeyNot($payment->id)
            ->whereDate('period_from', '<=', $payment->period_to)
            ->whereDate('period_to', '>=', $payment->period_from)
            ->orderBy('id')
            ->get(['id', 'payment_number', 'period_from', 'period_to', 'status']);
    }

    /**
     * @param  Collection<int, LabourAttendance>  $days
     * @return array<string, string>
     */
    private function lineTotals(Collection $days): array
    {
        return [
            'present_days' => (string) $days->where('status', AttendanceStatus::Present)->count().'.0',
            'half_days' => (string) $days->where('status', AttendanceStatus::HalfDay)->count().'.0',
            'ot_hours' => Decimal::sum($days->pluck('ot_hours')->all())->toMoney(),
            'gross_wage' => Decimal::sum($days->pluck('wage_amount')->all())->toMoney(),
            'ot_amount' => Decimal::sum($days->pluck('ot_amount')->all())->toMoney(),
        ];
    }

    private function net(LabourPaymentLine $line): Decimal
    {
        return Decimal::of($line->gross_wage)->plus($line->ot_amount)
            ->minus($line->advance_recovery)->minus($line->other_deductions);
    }

    private function assertDeductions(LabourPaymentLine $line, Decimal $recovery, Decimal $other, Decimal $recoverable, string $key): void
    {
        if ($recovery->greaterThan($recoverable)) {
            throw ValidationException::withMessages(["{$key}.advance_recovery" => 'Recovery cannot exceed the outstanding advance ('.$recoverable->toMoney().').']);
        }

        $payable = Decimal::of($line->gross_wage)->plus($line->ot_amount);
        if ($recovery->plus($other)->greaterThan($payable)) {
            throw ValidationException::withMessages(["{$key}.advance_recovery" => 'Deductions cannot exceed the amount payable ('.$payable->toMoney().').']);
        }
    }

    /**
     * Re-check every line's recovery against the advances under the labourer lock: at submit
     * against outstanding minus other pending batches, at approval against outstanding only.
     */
    private function assertRecoveries(LabourPayment $payment, bool $settledOnly): void
    {
        $lines = $payment->lines()->with('labour:id,name')->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['payment' => 'The payment has no lines.']);
        }

        foreach ($lines as $line) {
            if (Decimal::of($line->advance_recovery)->isZero()) {
                continue;
            }
            Labour::query()->withTrashed()->whereKey($line->labour_id)->lockForUpdate()->first();
            $limit = $settledOnly
                ? $this->advances->outstanding($line->labour_id)
                : $this->advances->recoverable($line->labour_id, $payment->id);

            if (Decimal::of($line->advance_recovery)->greaterThan($limit)) {
                throw ValidationException::withMessages(['payment' => "Recovery from {$line->labour?->name} exceeds the outstanding advance ({$limit->toMoney()})."]);
            }
        }
    }

    private function refreshTotals(LabourPayment $payment): void
    {
        $lines = LabourPaymentLine::query()->where('labour_payment_id', $payment->id)->get();

        $payment->forceFill([
            'total_gross' => Decimal::sum($lines->pluck('gross_wage')->all())->toMoney(),
            'total_ot' => Decimal::sum($lines->pluck('ot_amount')->all())->toMoney(),
            'total_deductions' => Decimal::sum($lines->map(fn ($l) => Decimal::of($l->advance_recovery)->plus($l->other_deductions))->all())->toMoney(),
            'total_net' => Decimal::sum($lines->pluck('net_amount')->all())->toMoney(),
        ])->save();
    }
}
