<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\PaymentMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Finance\Payment;
use App\Models\Projects\Project;
use App\Services\Finance\CashFlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash flow (cash documents only, never the cost ledger) and derived outstanding, per project and
 * across the projects the user can see. Needs payments.view.
 */
class CashFlowController extends Controller
{
    public function __construct(private readonly CashFlowService $cashFlow) {}

    public function project(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Payment::class, $project]);
        $filters = $this->filters($request);
        $entries = $this->cashFlow->entries([$project->id], $filters);

        return Inertia::render('Finance/CashFlow/Index', [
            'project' => ProjectHeader::for($project),
            'entries' => $entries->take(500)->values()->all(),
            'totals' => $this->cashFlow->totals($entries),
            'outstanding' => $this->cashFlow->outstanding([$project->id]),
            'filters' => $filters,
            ...$this->options(),
        ]);
    }

    public function company(Request $request): Response
    {
        abort_unless($request->user()->can('payments.view'), 403);
        $filters = $this->filters($request);
        $projects = Project::query()->visibleTo($request->user())->orderBy('code')->get(['id', 'code', 'name']);
        $entries = $this->cashFlow->entries($projects->modelKeys(), $filters);

        return Inertia::render('Finance/CashFlow/Company', [
            'entries' => $entries->take(500)->values()->all(),
            'totals' => $this->cashFlow->totals($entries),
            'outstanding' => $this->cashFlow->outstanding(filled($filters['project_id'] ?? null)
                ? array_values(array_intersect($projects->modelKeys(), [(int) $filters['project_id']]))
                : $projects->modelKeys()),
            'filters' => $filters,
            'projects' => $projects->map(fn (Project $p) => ['value' => $p->id, 'label' => "{$p->code} · {$p->name}"])->all(),
            ...$this->options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(CashFlowService::TYPES)],
            'party' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', Rule::enum(PaymentMode::class)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => [
                ['value' => 'receipt', 'label' => 'Receipts'],
                ['value' => 'payment', 'label' => 'Payments'],
                ['value' => 'expense', 'label' => 'Expenses paid'],
                ['value' => 'petty_cash_funding', 'label' => 'Petty cash funding'],
                ['value' => 'petty_cash_return', 'label' => 'Petty cash returns'],
                ['value' => 'labour_direct', 'label' => 'Labour paid directly'],
            ],
            'modes' => PaymentMode::options(),
        ];
    }
}
