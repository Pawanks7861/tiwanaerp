<?php

namespace App\Http\Resources;

use App\Enums\Finance\PaymentStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentAllocation;
use App\Services\Finance\PayableService;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $amounts = $user && FinancePresenter::seesPayments($user);
        $detail = str_contains((string) $request->route()?->getName(), '.show');
        $draft = $this->status === PaymentStatus::Draft;
        $selfRecorded = $user && (int) $this->created_by === (int) $user->id;

        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'party_type' => $this->party_type->value,
            'party_type_label' => $this->party_type->label(),
            'party_id' => $this->party_id,
            'party_name' => $this->partyName(),
            'mode' => $this->mode->value,
            'mode_label' => $this->mode->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount' => $this->when($amounts, $this->amount),
            'tds_amount' => $this->when($amounts, $this->tds_amount),
            'allocated' => $this->when($amounts, fn () => Decimal::sum($this->allocations->pluck('amount')->all())->toMoney()),
            'bank_reference' => $this->when($amounts && $detail, $this->bank_reference),
            'remarks' => $this->remarks,
            'cancellation_reason' => $this->when($this->status === PaymentStatus::Cancelled, $this->cancellation_reason),
            'allocations' => $this->whenLoaded('allocations', function () use ($amounts) {
                $payables = app(PayableService::class);

                return $this->allocations->map(function (PaymentAllocation $a) use ($amounts, $payables) {
                    $payable = $payables->classFor($a->payable_type)::query()->withTrashed()->find($a->payable_id);

                    return [
                        'id' => $a->id,
                        'payable_type' => $a->payable_type,
                        'payable_id' => $a->payable_id,
                        'amount' => $amounts ? $a->amount : null,
                        'document' => $payable ? $payables->label($payable) : null,
                        'outstanding' => $amounts && $payable ? $payables->outstanding($payable)->toMoney() : null,
                    ];
                })->values();
            }),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $draft && $user->can('update', $this->resource),
                'delete' => $draft && $user->can('delete', $this->resource),
                'approve' => $draft && ! $selfRecorded && $user->can('approve', $this->resource),
                'cancel' => $this->status === PaymentStatus::Approved && $user->can('cancel', $this->resource),
                'view_amounts' => $amounts,
            ] : null,
        ];
    }
}
