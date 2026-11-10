<?php

namespace App\Http\Resources;

use App\Enums\Quality\InspectionStatus;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityInspection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QualityInspection
 */
class QualityInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;
        $project = $this->relationLoaded('project') ? $this->project : null;

        return [
            'id' => $this->id,
            'inspection_number' => $this->inspection_number,
            'status' => $status->value,
            'status_label' => $status->label(),
            'result' => $this->result?->value,
            'result_label' => $this->result?->label(),
            'quality_checklist_id' => $this->quality_checklist_id,
            'checklist' => $this->whenLoaded('checklist', fn () => $this->checklist?->only(['id', 'name'])),
            'site_id' => $this->site_id,
            'site' => $this->whenLoaded('site', fn () => $this->site?->only(['id', 'name'])),
            'location' => $this->location,
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task ? [
                'id' => $this->task->id,
                'wbs_code' => $this->task->wbs_code,
                'name' => $this->task->name,
            ] : null),
            'boq_item_id' => $this->boq_item_id,
            'boq_item' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->only(['id', 'item_code', 'name'])),
            'request_notes' => $this->request_notes,
            'remarks' => $this->remarks,
            'inspection_date' => $this->inspection_date?->toDateString(),
            'engineer_id' => $this->engineer_id,
            'engineer' => $this->whenLoaded('engineer', fn () => $this->engineer?->only(['id', 'name'])),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->only(['id', 'name'])),
            'completed_by' => $this->whenLoaded('completer', fn () => $this->completer?->only(['id', 'name'])),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sequence' => $item->sort_order,
                'sort_order' => $item->sort_order,
                'checkpoint' => $item->checkpoint,
                'acceptance_criteria' => $item->acceptance_criteria,
                'result' => $item->result?->value,
                'remark' => $item->remark,
            ])->values()),
            'ncrs' => $this->whenLoaded('ncrs', fn () => $this->ncrs->map(fn (Ncr $n) => [
                'id' => $n->id,
                'ncr_number' => $n->ncr_number,
                'status' => $n->status->value,
                'severity' => $n->severity->value,
            ])->values()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? $this->abilities($user, $project) : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, ?Project $project): array
    {
        /** @var QualityInspection $inspection */
        $inspection = $this->resource;
        $status = $inspection->status;
        $project ??= $inspection->project;

        return [
            'update' => $status !== InspectionStatus::Completed && $user->can('edit', $inspection),
            'edit' => $status !== InspectionStatus::Completed && $user->can('edit', $inspection),
            'delete' => $status === InspectionStatus::Requested && $user->can('delete', $inspection),
            'schedule' => $status === InspectionStatus::Requested && $user->can('schedule', $inspection),
            'execute' => $status === InspectionStatus::Scheduled && $user->can('perform', $inspection),
            'perform' => $status === InspectionStatus::Scheduled && $user->can('perform', $inspection),
            'complete' => $status === InspectionStatus::Scheduled && $user->can('perform', $inspection),
            'cancel' => false,
            'attach' => $status !== InspectionStatus::Completed && $user->can('update', $inspection),
            'create_ncr' => $status === InspectionStatus::Completed && (bool) $inspection->result?->needsFollowUp()
                && $project !== null && $user->can('create', [Ncr::class, $project]),
            'raise_ncr' => $status === InspectionStatus::Completed && (bool) $inspection->result?->needsFollowUp()
                && $project !== null && $user->can('create', [Ncr::class, $project]),
        ];
    }
}
