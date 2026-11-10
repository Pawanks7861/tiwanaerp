<?php

namespace App\Http\Resources;

use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Http\Controllers\Labour\LabourPresenter;
use App\Services\Finance\PayableService;
use App\Models\Subcontract\SubcontractorBill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubcontractorBill
 */
class SubcontractorBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = $this->isEditable();
        $submitted = $this->status === SubcontractorBillStatus::Submitted;
        $costs = $user && LabourPresenter::seesWages($user);
        $settlements = $user && ($costs || FinancePresenter::seesPayments($user));
        $payables = $settlements ? app(PayableService::class) : null;

        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,
            'bill_date' => $this->bill_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subcontractor_invoice_no' => $this->subcontractor_invoice_no,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'remarks' => $this->remarks,
            'gross_amount' => $this->when($costs, $this->gross_amount),
            'tax_percent' => $this->when($costs, $this->tax_percent),
            'tax_amount' => $this->when($costs, $this->tax_amount),
            'retention_percent' => $this->when($costs, $this->retention_percent),
            'retention_amount' => $this->when($costs, $this->retention_amount),
            'advance_recovery' => $this->when($costs, $this->advance_recovery),
            'tds_percent' => $this->when($costs, $this->tds_percent),
            'tds_amount' => $this->when($costs, $this->tds_amount),
            'other_deductions' => $this->when($costs, $this->other_deductions),
            'net_payable' => $this->when($costs, $this->net_payable),
            'paid_amount' => $this->when($settlements, $this->paid_amount),
            'outstanding' => $this->when(
                $settlements && $this->status->isCertified(),
                fn () => $payables->outstanding($this->resource)->toMoney()
            ),
            'revision' => $this->revision,
            'subcontractor' => $this->whenLoaded('subcontractor', fn () => $this->subcontractor ? [
                'id' => $this->subcontractor->id,
                'code' => $this->subcontractor->code,
                'name' => $this->subcontractor->name,
                'gstin' => $this->subcontractor->getAttributes()['gstin'] ?? null,
            ] : null),
            'work_order' => $this->whenLoaded('workOrder', fn () => $this->workOrder ? [
                'id' => $this->workOrder->id,
                'wo_number' => $this->workOrder->wo_number,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'work_order_item_id' => $line->work_order_item_id,
                'wo_qty' => $line->wo_qty,
                'previous_qty' => $line->previous_qty,
                'claimed_qty' => $line->claimed_qty,
                'certified_qty' => $line->certified_qty,
                'cumulative_qty' => $line->cumulative_qty,
                'rate' => $this->when($costs, $line->rate),
                'amount' => $this->when($costs, $line->amount),
                'description' => $line->relationLoaded('workOrderItem') ? $line->workOrderItem?->description : null,
            ])->values()),
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
                'certify' => $submitted && $user->can('certify', $this->resource),
                'reverse' => $this->status === SubcontractorBillStatus::Certified && $user->can('reverse', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
                'view_costs' => $costs,
            ] : null,
        ];
    }
}
