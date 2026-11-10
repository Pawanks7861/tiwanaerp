<?php

namespace App\Services\Gate;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassReturnCondition;
use App\Enums\Gate\GatePassReturnStatus;
use App\Enums\Gate\GatePassStatus;
use App\Models\Equipment\Equipment;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassItem;
use App\Models\Gate\GatePassReturn;
use App\Models\Gate\GatePassReturnItem;
use App\Models\User;
use App\Services\Equipment\EquipmentLocationService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Line-wise RGP returns. Does not post stock; equipment location moves only on verified return.
 */
class GatePassReturnService
{
    public function __construct(private readonly EquipmentLocationService $locations) {}

    /**
     * @param  list<array{gate_pass_item_id:int, quantity:numeric-string|float|int, condition?:string, condition_notes?:?string}>  $lines
     */
    public function record(GatePass $pass, array $lines, User $user, ?string $remarks = null, ?string $returnDate = null): GatePassReturn
    {
        return DB::transaction(function () use ($pass, $lines, $user, $remarks, $returnDate) {
            $pass = GatePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            $this->assertReturnable($pass);

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Add at least one return line.']);
            }

            $return = new GatePassReturn;
            $return->forceFill([
                'gate_pass_id' => $pass->id,
                'return_date' => $returnDate ?? now()->toDateString(),
                'returned_by' => $user->id,
                'status' => GatePassReturnStatus::Recorded,
                'remarks' => $remarks,
            ])->save();

            foreach ($lines as $index => $line) {
                $key = "items.{$index}";
                $item = GatePassItem::query()->where('gate_pass_id', $pass->id)->whereKey((int) ($line['gate_pass_item_id'] ?? 0))->first()
                    ?? throw ValidationException::withMessages(["{$key}.gate_pass_item_id" => 'Invalid gate pass line.']);

                $qty = Decimal::of($line['quantity'] ?? 0);
                if (! $qty->isPositive()) {
                    throw ValidationException::withMessages(["{$key}.quantity" => 'Return quantity must be positive.']);
                }

                $pending = $this->pendingQuantity($item);
                if ($qty->greaterThan($pending)) {
                    throw ValidationException::withMessages(["{$key}.quantity" => "Only {$pending->toQuantity()} pending return on this line."]);
                }

                $condition = GatePassReturnCondition::tryFrom((string) ($line['condition'] ?? 'good')) ?? GatePassReturnCondition::Good;

                (new GatePassReturnItem)->forceFill([
                    'gate_pass_return_id' => $return->id,
                    'gate_pass_item_id' => $item->id,
                    'quantity' => $qty->toQuantity(),
                    'condition' => $condition,
                    'condition_notes' => $line['condition_notes'] ?? null,
                ])->save();
            }

            return $return->load('items');
        });
    }

    public function verify(GatePassReturn $return, User $user, bool $allowSelfVerify = false): GatePassReturn
    {
        return DB::transaction(function () use ($return, $user, $allowSelfVerify) {
            $return = GatePassReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status === GatePassReturnStatus::Verified) {
                return $return;
            }

            if (! $allowSelfVerify && (int) $return->returned_by === (int) $user->id) {
                throw ValidationException::withMessages(['return' => 'The user who recorded the return cannot verify it.']);
            }

            $pass = GatePass::query()->whereKey($return->gate_pass_id)->lockForUpdate()->firstOrFail();
            $this->assertReturnable($pass);

            $return->forceFill([
                'status' => GatePassReturnStatus::Verified,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ])->save();

            foreach ($return->items()->with('gatePassItem')->get() as $line) {
                $item = $line->gatePassItem;
                if ($item?->equipment_id) {
                    $equipment = Equipment::query()->find($item->equipment_id);
                    if ($equipment) {
                        $this->locations->change($equipment, [
                            'location_type' => EquipmentLocationType::ProjectSite->value,
                            'location_project_id' => $pass->project_id,
                            'location_name' => $pass->project?->name,
                            'remarks' => "Returned on {$pass->pass_number}",
                        ], $user);
                    }
                }
            }

            if ($this->fullyReturned($pass)) {
                $pass->forceFill([
                    'status' => GatePassStatus::Closed,
                    'returned_at' => now(),
                ])->save();
            }

            return $return->fresh(['items']);
        });
    }

    /**
     * Compatibility: return all remaining qty and verify when the actor may verify; otherwise leave recorded.
     */
    public function markFullyReturned(GatePass $pass, User $user): GatePassReturn
    {
        $lines = $pass->items->map(function (GatePassItem $item) {
            $pending = $this->pendingQuantity($item);
            if (! $pending->isPositive()) {
                return null;
            }

            return [
                'gate_pass_item_id' => $item->id,
                'quantity' => $pending->toQuantity(),
                'condition' => GatePassReturnCondition::Good->value,
            ];
        })->filter()->values()->all();

        $return = $this->record($pass, $lines, $user, 'Full remaining quantity returned.');

        // Legacy markReturned is a single-user close action; allow self-verify only on this path.
        return $this->verify($return, $user, allowSelfVerify: true);
    }

    public function pendingQuantity(GatePassItem $item): Decimal
    {
        $returned = GatePassReturnItem::query()
            ->where('gate_pass_item_id', $item->id)
            ->whereHas('gatePassReturn', fn ($q) => $q->where('status', GatePassReturnStatus::Verified))
            ->sum('quantity');

        $pending = Decimal::of($item->quantity)->minus($returned);

        return $pending->isNegative() ? Decimal::zero() : $pending;
    }

    public function fullyReturned(GatePass $pass): bool
    {
        foreach ($pass->items()->get() as $item) {
            if ($this->pendingQuantity($item)->isPositive()) {
                return false;
            }
        }

        return $pass->items()->exists();
    }

    private function assertReturnable(GatePass $pass): void
    {
        if (! $pass->returnable || $pass->direction !== GatePassDirection::Out) {
            throw ValidationException::withMessages(['gate_pass' => 'Only a returnable outward pass can record returns.']);
        }
        if (! in_array($pass->status, [GatePassStatus::Verified, GatePassStatus::Approved], true)) {
            throw ValidationException::withMessages(['gate_pass' => 'Verify the outward pass before recording returns.']);
        }
        if ($pass->status === GatePassStatus::Closed) {
            throw ValidationException::withMessages(['gate_pass' => 'This gate pass is already closed.']);
        }
    }
}
