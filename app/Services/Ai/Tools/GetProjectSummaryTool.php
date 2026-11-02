<?php

namespace App\Services\Ai\Tools;

use App\Models\Planning\ProjectMilestone;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class GetProjectSummaryTool implements AiTool
{
    public function name(): string
    {
        return 'getProjectSummary';
    }

    public function description(): string
    {
        return 'Count active projects and upcoming/overdue milestones for accessible projects.';
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
        if (! $user->can('projects.view')) {
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
            return ['error' => 'restricted', 'message' => 'Project information is restricted for your role.'];
        }

        $projects = Project::query()->visibleTo($user);
        if (! empty($arguments['project_id'])) {
            $projects->whereKey((int) $arguments['project_id']);
        }

        $all = (clone $projects)->get(['id', 'code', 'name', 'status']);
        $active = $all->filter(function ($p) {
            $status = is_object($p->status) ? $p->status->value : (string) $p->status;

            return ! in_array($status, ['completed', 'closed', 'cancelled', 'archived'], true);
        });

        $upcoming = 0;
        $overdue = 0;
        $milestoneRows = [];

        if (Schema::hasTable('project_milestones')) {
            $ids = $all->pluck('id');
            $today = CarbonImmutable::now()->toDateString();
            $until = CarbonImmutable::now()->addDays(30)->toDateString();

            $q = ProjectMilestone::query()->whereIn('project_id', $ids)->whereNull('completed_at');
            $upcoming = (clone $q)->whereBetween('due_date', [$today, $until])->count();
            $overdue = (clone $q)->where('due_date', '<', $today)->count();

            $milestoneRows = (clone $q)
                ->orderBy('due_date')
                ->limit(8)
                ->get(['project_id', 'name', 'due_date', 'status'])
                ->map(function ($m) use ($all) {
                    $project = $all->firstWhere('id', $m->project_id);

                    return [
                        'project' => $project?->name,
                        'code' => $project?->code,
                        'name' => $m->name,
                        'due_date' => optional($m->due_date)->toDateString() ?? (string) $m->due_date,
                        'status' => is_object($m->status) ? $m->status->value : (string) $m->status,
                    ];
                })->all();
        }

        return [
            'active_count' => $active->count(),
            'total_count' => $all->count(),
            'upcoming_milestones' => $upcoming,
            'overdue_milestones' => $overdue,
            'milestones' => $milestoneRows,
        ];
    }
}
