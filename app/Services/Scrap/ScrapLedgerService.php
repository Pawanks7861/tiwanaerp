<?php

namespace App\Services\Scrap;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Models\Masters\ScrapType;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapBalance;
use App\Models\Scrap\ScrapLedgerEntry;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of scrap_ledger_entries and scrap_balances.
 */
class ScrapLedgerService
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function post(ScrapMovement $movement): ScrapLedgerEntry
    {
        $quantity = $movement->quantity->round(Decimal::QTY_SCALE);
        if (! $quantity->isPositive()) {
            throw new LogicException('A scrap movement needs a positive quantity.');
        }

        if ($movement->type === ScrapLedgerTxnType::Reversal) {
            throw new LogicException('Reversals are posted with reverse().');
        }

        $scrapType = $this->assertTenant($movement->project, $movement->scrapTypeId);

        $date = $movement->date instanceof \Carbon\CarbonInterface
            ? $movement->date->toDateString()
            : (string) $movement->date;

        return DB::transaction(function () use ($movement, $quantity, $scrapType, $date) {
            $balance = $this->lockBalance($movement->project->id, $scrapType->id, $movement->unitId);

            $existing = ScrapLedgerEntry::query()
                ->where('reference_type', $movement->reference->getMorphClass())
                ->where('reference_id', $movement->reference->getKey())
                ->where('transaction_type', $movement->type)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($movement->type->isIncoming()) {
                [$qtyIn, $qtyOut] = [$quantity, Decimal::zero()];
            } else {
                $available = Decimal::of($balance->quantity);
                if ($quantity->greaterThan($available)) {
                    throw ValidationException::withMessages([$movement->errorKey => "Insufficient scrap of {$scrapType->name}: available {$available->toQuantity()}, required {$quantity->toQuantity()}."]);
                }
                [$qtyIn, $qtyOut] = [Decimal::zero(), $quantity];
            }

            $entry = new ScrapLedgerEntry;
            $entry->forceFill([
                'project_id' => $movement->project->id,
                'scrap_type_id' => $scrapType->id,
                'unit_id' => $movement->unitId,
                'transaction_date' => $date,
                'transaction_type' => $movement->type,
                'quantity_in' => $qtyIn->toQuantity(),
                'quantity_out' => $qtyOut->toQuantity(),
                'reference_type' => $movement->reference->getMorphClass(),
                'reference_id' => $movement->reference->getKey(),
                'reference_number' => $movement->referenceNumber,
                'remarks' => $movement->remarks,
                'created_by' => $movement->userId ?? Auth::id(),
            ])->save();

            $this->apply($balance, $movement->type, $qtyIn, $qtyOut);

            return $entry;
        });
    }

    public function lockBalance(int $projectId, int $scrapTypeId, int $unitId): ScrapBalance
    {
        $find = fn () => ScrapBalance::query()
            ->where('project_id', $projectId)
            ->where('scrap_type_id', $scrapTypeId)
            ->where('unit_id', $unitId)
            ->lockForUpdate()
            ->first();

        if ($balance = $find()) {
            return $balance;
        }

        DB::table('scrap_balances')->insertOrIgnore([
            'company_id' => $this->tenancy->require()->id,
            'project_id' => $projectId,
            'scrap_type_id' => $scrapTypeId,
            'unit_id' => $unitId,
            'quantity' => 0,
            'qty_generated' => 0,
            'qty_dispatched' => 0,
            'qty_adjusted_net' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $find() ?? throw new LogicException('Scrap balance row could not be locked.');
    }

    private function apply(ScrapBalance $balance, ScrapLedgerTxnType $type, Decimal $qtyIn, Decimal $qtyOut): void
    {
        $net = $qtyIn->minus($qtyOut);
        $quantity = Decimal::of($balance->quantity)->plus($net)->round(Decimal::QTY_SCALE);

        if ($quantity->isNegative()) {
            throw new LogicException('Scrap balance would become negative.');
        }

        $generated = Decimal::of($balance->qty_generated);
        $dispatched = Decimal::of($balance->qty_dispatched);
        $adjusted = Decimal::of($balance->qty_adjusted_net);

        if ($type === ScrapLedgerTxnType::GenerationIn) {
            $generated = $generated->plus($qtyIn);
        } elseif ($type === ScrapLedgerTxnType::GatePassOut) {
            $dispatched = $dispatched->plus($qtyOut);
        } elseif ($type === ScrapLedgerTxnType::AdjustmentIn) {
            $adjusted = $adjusted->plus($qtyIn);
        } elseif ($type === ScrapLedgerTxnType::AdjustmentOut) {
            $adjusted = $adjusted->minus($qtyOut);
        } elseif ($type === ScrapLedgerTxnType::GatePassReturnIn) {
            $dispatched = $dispatched->minus($qtyIn);
        }

        $balance->forceFill([
            'quantity' => $quantity->toQuantity(),
            'qty_generated' => $generated->toQuantity(),
            'qty_dispatched' => $dispatched->toQuantity(),
            'qty_adjusted_net' => $adjusted->toQuantity(),
            'last_movement_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    private function assertTenant(Project $project, int $scrapTypeId): ScrapType
    {
        $companyId = $this->tenancy->require()->id;

        if ((int) $project->company_id !== $companyId) {
            throw new LogicException('The project belongs to a different company.');
        }

        return ScrapType::query()->withTrashed()->findOrFail($scrapTypeId);
    }
}
