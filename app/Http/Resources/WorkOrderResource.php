<?php

namespace App\Http\Resources;

use App\Enums\Subcontract\WorkOrderStatus;
use App\Models\Subcontract\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkOrder
 */
class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = $this->isEditable();
        $status = $this->status;
        $billable = $status->isBillable();

        return [
            'id' => $this->id,
            'wo_number' => $this->wo_number,
            'wo_date' => $this->wo_date?->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'subject' => $this->subject,
            'template_profile' => $this->template_profile,
            'pdf_hide_quantities' => (bool) $this->pdf_hide_quantities,
            'scope' => $this->scope,
            'contractor_scope' => $this->contractor_scope,
            'company_scope' => $this->company_scope,
            'payment_terms' => $this->payment_terms,
            'terms' => $this->terms,
            'safety_terms' => $this->safety_terms,
            'penalty_terms' => $this->penalty_terms,
            'delay_penalty_per_day' => $this->delay_penalty_per_day,
            'maximum_penalty_percent' => $this->maximum_penalty_percent,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'retention_percent' => $this->retention_percent,
            'advance_amount' => $this->advance_amount,
            'tax_rate_id' => $this->tax_rate_id,
            'tax_percent' => $this->tax_percent,
            'tds_percent' => $this->tds_percent,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_value' => $this->total_value,
            'subcontractor' => $this->whenLoaded('subcontractor', fn () => $this->subcontractor ? [
                'id' => $this->subcontractor->id,
                'code' => $this->subcontractor->code,
                'name' => $this->subcontractor->name,
                'gstin' => $this->subcontractor->getAttributes()['gstin'] ?? null,
                'mobile' => $this->subcontractor->getAttributes()['mobile'] ?? null,
            ] : null),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
                'boq_item_id' => $line->boq_item_id,
                'task_id' => $line->task_id,
                'unit' => $line->relationLoaded('unit') ? $line->unit?->only(['id', 'symbol']) : null,
                'boq_item' => $line->relationLoaded('boqItem') && $line->boqItem
                    ? $line->boqItem->only(['id', 'item_code', 'name']) : null,
                'task' => $line->relationLoaded('task') && $line->task
                    ? $line->task->only(['id', 'wbs_code', 'name']) : null,
            ])->values()),
            'milestones' => $this->whenLoaded('milestones', fn () => $this->milestones->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'due_date' => $m->due_date?->toDateString(),
                'amount_percent' => $m->amount_percent,
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
            ])->values()),
            'approval' => $this->when(
                $this->relationLoaded('latestApprovalRequest') && $this->latestApprovalRequest,
                fn () => ApprovalSummaryResource::make($this->latestApprovalRequest)->resolve()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? [
                'view' => $user->can('view', $this->resource),
                'update' => $editable && $user->can('update', $this->resource),
                'delete' => $status === WorkOrderStatus::Draft && $user->can('delete', $this->resource),
                'submit' => $editable && $user->can('submit', $this->resource),
                'complete' => in_array($status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true) && $user->can('complete', $this->resource),
                'close' => $billable && $user->can('close', $this->resource),
                'cancel' => in_array($status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true) && $user->can('cancel', $this->resource),
                'milestones' => $billable && $user->can('updateMilestones', $this->resource),
                'attach' => $editable && $user->can('update', $this->resource),
                'export' => $user->can('export', $this->resource),
            ] : null,
        ];
    }
}
