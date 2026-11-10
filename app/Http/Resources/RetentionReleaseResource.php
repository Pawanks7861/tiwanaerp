<?php

namespace App\Http\Resources;

use App\Enums\Finance\RetentionReleaseStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\RetentionRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RetentionRelease
 */
class RetentionReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $amounts = $user && (FinancePresenter::seesBilling($user) || $user->can('subcontract.view'));
        $editable = $this->isEditable();

        return [
            'id' => $this->id,
            'release_number' => $this->release_number,
            'release_date' => $this->release_date?->toDateString(),
            'releasable_type' => $this->releasable_type,
            'releasable_id' => $this->releasable_id,
            'amount' => $this->when($amounts, $this->amount),
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'bill' => $this->whenLoaded('releasable', function () {
                $bill = $this->releasable;
                if ($bill === null) {
                    return null;
                }

                return [
                    'type' => $bill instanceof ClientInvoice ? 'client_invoice' : 'subcontractor_bill',
                    'id' => $bill->getKey(),
                    'number' => $bill instanceof ClientInvoice ? $bill->displayNumber() : $bill->bill_number,
                ];
            }),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'view_amounts' => $amounts,
            ] : null,
        ];
    }
}
