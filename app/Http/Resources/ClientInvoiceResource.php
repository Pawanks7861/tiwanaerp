<?php

namespace App\Http\Resources;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Models\Finance\ClientInvoice;
use App\Services\Finance\PayableService;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientInvoice
 */
class ClientInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $amounts = $user && FinancePresenter::seesBilling($user);
        $editable = $this->isEditable();
        $submitted = $this->status === ClientInvoiceStatus::Submitted;
        $certified = $this->isCertified();
        $payables = $amounts && $certified ? app(PayableService::class) : null;
        $outstanding = $payables ? $payables->outstanding($this->resource)->toMoney() : null;
        $received = $amounts ? (string) $this->received_amount : null;
        $pendingApproval = $submitted && $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest;

        return [
            'id' => $this->id,
            'ra_sequence' => $this->ra_sequence,
            'invoice_number' => $this->invoice_number,
            'display_number' => $this->displayNumber(),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tax_type' => $this->tax_type->value,
            'remarks' => $this->remarks,
            'gross_amount' => $this->when($amounts, $this->gross_amount),
            'tax_amount' => $this->when($amounts, $this->tax_amount),
            'invoice_total' => $this->when($amounts, $this->invoice_total),
            'retention_percent' => $this->when($amounts, $this->retention_percent),
            'retention_amount' => $this->when($amounts, $this->retention_amount),
            'advance_recovery' => $this->when($amounts, $this->advance_recovery),
            'tds_percent' => $this->when($amounts, $this->tds_percent),
            'tds_amount' => $this->when($amounts, $this->tds_amount),
            'other_deductions' => $this->when($amounts, $this->other_deductions),
            'net_payable' => $this->when($amounts, $this->net_payable),
            'received_amount' => $received,
            'outstanding' => $this->when($amounts && $certified, $outstanding),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'code' => $this->client->code,
                'company_name' => $this->client->company_name,
                'gstin' => $this->client->getAttributes()['gstin'] ?? null,
            ] : null),
            'tax_rate' => $this->whenLoaded('taxRate', fn () => $this->taxRate?->only(['id', 'name'])),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'boq_item_id' => $line->boq_item_id,
                'boq_line_uid' => $line->boq_line_uid,
                'item_code' => $line->item_code,
                'description' => $line->description,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->symbol : null,
                'boq_qty' => $line->boq_qty,
                'executed_qty' => $line->executed_qty,
                'previous_qty' => $line->previous_qty,
                'current_qty' => $line->current_qty,
                'cumulative_qty' => $line->cumulative_qty,
                'rate' => $this->when($amounts, $line->rate),
                'current_amount' => $this->when($amounts, $line->current_amount),
                'is_override' => $line->is_override,
                'override_reason' => $line->override_reason,
            ])->values()),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $this->status === ClientInvoiceStatus::Draft && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'certify_via_approvals' => $pendingApproval && $user->can('billing.certify'),
                'view_pdf' => $user->can('export', $this->resource),
                'create_receipt' => $certified
                    && $outstanding !== null
                    && Decimal::of($outstanding)->isPositive()
                    && $user->can('create', [\App\Models\Finance\Payment::class, $this->project]),
                'view_amounts' => $amounts,
            ] : null,
        ];
    }
}
