<?php

namespace App\Http\Resources;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
class ApprovalSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $pending = $this->status === ApprovalStatus::Pending;

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'current_level' => $this->current_level,
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter?->only(['id', 'name'])),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($a) => [
                'id' => $a->id,
                'level' => $a->level,
                'action' => $a->action?->value,
                'comments' => $a->comments,
                'acted_at' => $a->acted_at?->toIso8601String(),
                'user' => $a->relationLoaded('user') ? $a->user?->only(['id', 'name']) : null,
            ])->values()),
            'canApprove' => $pending && $user?->can('approvals.view'),
            'canReject' => $pending && $user?->can('approvals.view'),
            'canSendBack' => $pending && $user?->can('approvals.view'),
        ];
    }
}
