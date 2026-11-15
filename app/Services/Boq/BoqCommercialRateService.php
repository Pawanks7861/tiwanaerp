<?php

namespace App\Services\Boq;

use App\Models\Boq\Boq;
use App\Models\User;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates allowed commercial fields after Technical Approval and before final approval.
 */
class BoqCommercialRateService
{
    /**
     * @param  list<array{id: int, client_rate: string|null, margin_percent?: string|null}>  $rows
     */
    public function updateRates(Boq $boq, array $rows, string $reason, User $user): Boq
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for commercial rate changes.']);
        }

        if (! BoqCompanySettings::enabled()) {
            throw ValidationException::withMessages(['boq' => 'Commercial rate editing during review is only available for Advanced BOQ.']);
        }

        if (! $boq->allowsCommercialRateEdits()) {
            throw ValidationException::withMessages([
                'boq' => 'Commercial rates can only be edited after technical approval and before final approval.',
            ]);
        }

        if (! $user->can('boq.view_costs') || ! $user->can('boq.manage_cost')) {
            throw ValidationException::withMessages(['boq' => 'You do not have permission to edit commercial rates.']);
        }

        return DB::transaction(function () use ($boq, $rows, $reason, $user) {
            $locked = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            if (! $locked->allowsCommercialRateEdits()) {
                throw ValidationException::withMessages([
                    'boq' => 'Commercial rates can only be edited after technical approval and before final approval.',
                ]);
            }

            $items = $locked->items()->get()->keyBy('id');
            $changes = [];

            foreach (array_values($rows) as $i => $row) {
                $id = (int) ($row['id'] ?? 0);
                $item = $items->get($id)
                    ?? throw ValidationException::withMessages(["rows.{$i}.id" => 'This line does not belong to the BOQ.']);

                $newRate = array_key_exists('client_rate', $row) ? (string) $row['client_rate'] : (string) $item->client_rate;
                if ($newRate === '' || Decimal::of($newRate)->isNegative()) {
                    throw ValidationException::withMessages(["rows.{$i}.client_rate" => 'Enter a valid non-negative client rate.']);
                }

                $oldRate = (string) $item->client_rate;
                $oldMargin = (string) $item->margin_percent;
                $newMargin = array_key_exists('margin_percent', $row) && $row['margin_percent'] !== null
                    ? (string) $row['margin_percent']
                    : $oldMargin;

                $qty = Decimal::of((string) $item->quantity);
                $clientAmount = $qty->times($newRate)->toMoney();

                $item->setRelation('boq', $locked);
                $item->forceFill([
                    'client_rate' => Decimal::of($newRate)->toRate(),
                    'client_amount' => $clientAmount,
                    'margin_percent' => Decimal::of($newMargin)->toRate(),
                ])->save();

                if ($oldRate !== (string) $item->client_rate || $oldMargin !== (string) $item->margin_percent) {
                    $changes[] = [
                        'item_id' => $item->id,
                        'line_uid' => $item->line_uid,
                        'old_client_rate' => $oldRate,
                        'new_client_rate' => (string) $item->client_rate,
                        'old_margin_percent' => $oldMargin,
                        'new_margin_percent' => (string) $item->margin_percent,
                    ];
                }
            }

            $clientTotal = Decimal::sum(
                $locked->items()->get(['client_amount'])->pluck('client_amount')
            )->toMoney();
            $locked->forceFill(['total_client_amount' => $clientTotal])->save();

            if ($changes !== []) {
                $locked->writeAudit('commercial_rates_updated', null, [
                    'reason' => $reason,
                    'changed_by' => $user->id,
                    'changed_at' => now()->toIso8601String(),
                    'changes' => $changes,
                ]);
            }

            return $locked->fresh();
        });
    }
}
