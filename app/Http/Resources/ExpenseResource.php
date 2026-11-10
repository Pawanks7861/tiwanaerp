<?php

namespace App\Http\Resources;

use App\Enums\Finance\ExpenseStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $amounts = $user && FinancePresenter::seesExpenses($user);
        $editable = $this->isEditable();

        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'expense_date' => $this->expense_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'reference_no' => $this->reference_no,
            'payment_mode' => $this->payment_mode->value,
            'payment_mode_label' => $this->payment_mode->label(),
            'amount' => $this->when($amounts, $this->amount),
            'tax_amount' => $this->when($amounts, $this->tax_amount),
            'total_amount' => $this->when($amounts, $this->total_amount),
            'cost_head' => $this->cost_head->value,
            'cost_head_label' => $this->cost_head->label(),
            'payee_name' => $this->payee_name,
            'paid_on' => $this->paid_on?->toDateString(),
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'name'])),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ] : null),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $editable && $this->revision === 0 && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'mark_paid' => $this->status === ExpenseStatus::Approved && ! $this->isPettyCash() && $user->can('markPaid', $this->resource),
                'reverse' => ($this->status === ExpenseStatus::Approved || ($this->status === ExpenseStatus::Paid && $this->isPettyCash()))
                    && $user->can('reverse', $this->resource),
                'view_amounts' => $amounts,
            ] : null,
        ];
    }
}
