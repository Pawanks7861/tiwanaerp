<?php

namespace App\Services\Ai\Tools;

use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;

class GetDrawingApprovalSummaryTool implements AiTool
{
    public function name(): string
    {
        return 'getDrawingApprovalSummary';
    }

    public function description(): string
    {
        return 'Count drawing revisions by workflow status for accessible projects. Useful for pending approval questions.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (! $user->can('drawings.view')) {
            return false;
        }
        if (! empty($arguments['project_id'])) {
            $project = Project::query()->find((int) $arguments['project_id']);

            return $project !== null && $user->can('view', $project);
        }

        return true;
    }

    public function handle(User $user, array $arguments): array
    {
        if (! $this->authorize($user, $arguments)) {
            return ['error' => 'restricted', 'message' => 'Drawing information is restricted for your role.'];
        }

        $query = DrawingRevision::query();
        if (! empty($arguments['project_id'])) {
            $query->whereHas('drawing', fn ($q) => $q->where('project_id', (int) $arguments['project_id']));
        } else {
            $ids = Project::query()->visibleTo($user)->pluck('id');
            $query->whereHas('drawing', fn ($q) => $q->whereIn('project_id', $ids));
        }

        $counts = [];
        foreach (DrawingRevisionStatus::cases() as $status) {
            $counts[$status->value] = (clone $query)->where('status', $status)->count();
        }

        return [
            'project_id' => isset($arguments['project_id']) ? (int) $arguments['project_id'] : null,
            'counts' => $counts,
            'pending_approval' => ($counts[DrawingRevisionStatus::Submitted->value] ?? 0)
                + ($counts[DrawingRevisionStatus::UnderReview->value] ?? 0),
        ];
    }
}
