<?php

namespace App\Services\Ai\Tools;

use App\Models\Finance\Expense;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

class GetExpenseSummaryTool implements AiTool
{
    public function __construct(private readonly CurrentCompany $company) {}

    public function name(): string
    {
        return 'getExpenseSummary';
    }

    public function description(): string
    {
        return 'Summarize project expenses for a period. Uses expense header totals. Drafts can be excluded.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'enum' => ['this_month', 'last_month', 'today']],
                'project_id' => ['type' => 'integer'],
                'approved_only' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (! $user->can('expenses.view')) {
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
            return ['error' => 'restricted', 'message' => 'Expense information is restricted.'];
        }

        $tz = $this->company->get()?->timezone ?: config('app.timezone', 'Asia/Kolkata');
        $now = CarbonImmutable::now($tz);
        $period = $arguments['period'] ?? 'this_month';
        [$from, $to, $label] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay(), 'Today'],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth(), $now->subMonth()->format('F Y')],
            default => [$now->startOfMonth(), $now->endOfMonth(), $now->format('F Y')],
        };

        $query = Expense::query()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);
        if (! empty($arguments['project_id'])) {
            $query->where('project_id', (int) $arguments['project_id']);
        } else {
            $query->whereIn('project_id', Project::query()->visibleTo($user)->select('id'));
        }
        if ($arguments['approved_only'] ?? true) {
            $query->where('status', \App\Enums\Finance\ExpenseStatus::Approved);
        }

        $canMoney = $user->can('dashboard.view_financials') || $user->can('reports.view_financial');

        return [
            'period_label' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => (clone $query)->count(),
            'total_amount' => $canMoney ? (string) ((clone $query)->sum('total_amount') ?? '0') : null,
            'currency' => $this->company->get()?->currency ?: 'INR',
        ];
    }
}
