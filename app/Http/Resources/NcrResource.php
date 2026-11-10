<?php

namespace App\Http\Resources;

use App\Enums\Quality\NcrStatus;
use App\Models\Quality\Ncr;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ncr
 */
class NcrResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $status = $this->status;

        return [
            'id' => $this->id,
            'ncr_number' => $this->ncr_number,
            'status' => $status->value,
            'status_label' => $status->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'issue' => $this->issue,
            'issue_excerpt' => str($this->issue)->limit(90)->toString(),
            'location' => $this->location,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'verification_remarks' => $this->verification_remarks,
            'quality_inspection_id' => $this->quality_inspection_id,
            'inspection' => $this->whenLoaded('inspection', fn () => $this->inspection?->only(['id', 'inspection_number', 'result'])),
            'responsible_user_id' => $this->responsible_user_id,
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser?->only(['id', 'name'])),
            'subcontractor_id' => $this->subcontractor_id,
            'subcontractor' => $this->whenLoaded('subcontractor', fn () => $this->subcontractor?->only(['id', 'name', 'code'])),
            'target_date' => $this->target_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'overdue' => $this->isOverdue(),
            'overdue_days' => $this->overdueDays(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->only(['id', 'name'])),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->only(['id', 'name'])),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'closed_by' => $this->whenLoaded('closer', fn () => $this->closer?->only(['id', 'name'])),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'raised_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'raised_at' => $this->created_at?->toIso8601String(),
            'history' => $this->lifecycleHistory(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'can' => $user ? $this->abilities($user) : null,
        ];
    }

    private function isOverdue(): bool
    {
        $status = $this->status;

        return $this->target_date !== null
            && $this->target_date->isPast()
            && ! $this->target_date->isToday()
            && ! in_array($status, [NcrStatus::Verified, NcrStatus::Closed], true);
    }

    private function overdueDays(): ?int
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return (int) $this->target_date->diffInDays(now()->startOfDay());
    }

    /**
     * @return list<array{event: string, at: string|null, by: array{id: int, name: string}|null}>
     */
    private function lifecycleHistory(): array
    {
        $events = [
            ['event' => 'raised', 'at' => $this->created_at?->toIso8601String(), 'by' => $this->relationLoaded('creator') ? $this->creator?->only(['id', 'name']) : null],
            ['event' => 'resolved', 'at' => $this->resolved_at?->toIso8601String(), 'by' => $this->relationLoaded('resolver') ? $this->resolver?->only(['id', 'name']) : null],
            ['event' => 'verified', 'at' => $this->verified_at?->toIso8601String(), 'by' => $this->relationLoaded('verifier') ? $this->verifier?->only(['id', 'name']) : null],
            ['event' => 'closed', 'at' => $this->closed_at?->toIso8601String(), 'by' => $this->relationLoaded('closer') ? $this->closer?->only(['id', 'name']) : null],
        ];

        return array_values(array_filter($events, fn (array $row) => $row['at'] !== null));
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        /** @var Ncr $ncr */
        $ncr = $this->resource;
        $status = $ncr->status;

        return [
            'update' => $status->isEditable() && $user->can('update', $ncr),
            'delete' => $status === NcrStatus::Open && $user->can('delete', $ncr),
            'start' => $status === NcrStatus::Open && $user->can('start', $ncr),
            'resolve' => $status === NcrStatus::InProgress && $user->can('resolve', $ncr),
            'verify' => $status === NcrStatus::Resolved
                && (int) $ncr->resolved_by !== (int) $user->id
                && $user->can('verify', $ncr),
            'reopen' => $status === NcrStatus::Resolved && $user->can('reopen', $ncr),
            'close' => $status === NcrStatus::Verified && $user->can('close', $ncr),
            'attach' => $status->isEditable() && $user->can('update', $ncr),
        ];
    }
}
