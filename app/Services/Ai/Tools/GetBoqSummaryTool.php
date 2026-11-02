<?php

namespace App\Services\Ai\Tools;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Support\Tenancy\CurrentCompany;

class GetBoqSummaryTool implements AiTool
{
    public function __construct(private readonly CurrentCompany $company) {}

    public function name(): string
    {
        return 'getBoqSummary';
    }

    public function description(): string
    {
        return 'Summarize BOQ counts and totals for accessible projects.';
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
        if (! $user->can('boq.view')) {
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
            return ['error' => 'restricted', 'message' => 'BOQ information is restricted for your role.'];
        }

        $query = Boq::query();
        if (! empty($arguments['project_id'])) {
            $query->where('project_id', (int) $arguments['project_id']);
        } else {
            $query->whereIn('project_id', Project::query()->visibleTo($user)->select('id'));
        }

        $approved = (clone $query)->where('status', BoqStatus::Approved)->where('is_current', true);
        $canMoney = $user->can('boq.view_costs')
            || $user->can('dashboard.view_financials')
            || $user->can('reports.view_financial');

        $boqIds = (clone $query)->pluck('id');

        return [
            'project_id' => isset($arguments['project_id']) ? (int) $arguments['project_id'] : null,
            'boq_count' => (clone $query)->count(),
            'approved_current_count' => (clone $approved)->count(),
            'total_cost_amount' => $canMoney ? (string) ((clone $approved)->sum('total_cost_amount') ?? '0') : null,
            'total_client_amount' => $canMoney ? (string) ((clone $approved)->sum('total_client_amount') ?? '0') : null,
            'totals_visible' => $canMoney,
            'item_count' => BoqItem::query()->whereIn('boq_id', $boqIds)->count(),
            'currency' => $this->company->get()?->currency ?: 'INR',
        ];
    }
}
