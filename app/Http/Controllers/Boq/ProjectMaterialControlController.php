<?php

namespace App\Http\Controllers\Boq;

use App\Exports\ProjectMaterialControlExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Projects\Project;
use App\Services\Boq\ProjectMaterialControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectMaterialControlController extends Controller
{
    public function __construct(private readonly ProjectMaterialControlService $control) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation') || $request->user()->can('reports.view'), 403);

        $filters = $this->filters($request);
        $result = $this->control->report($project, $filters);
        $canCosts = $request->user()->can('boq.view_costs');
        if (! $canCosts) {
            $result = $this->control->redactCosts($result);
        }

        return Inertia::render('Boq/MaterialControl', [
            'project' => ProjectHeader::for($project),
            'summary' => $result['summary'],
            'rows' => $result['rows'],
            'pagination' => $result['pagination'],
            'meta' => $result['meta'],
            'filters' => $filters,
            'formula_notes' => $result['formula_notes'],
            'materials' => Material::query()->active()->orderBy('name')->limit(2000)
                ->get(['id', 'code', 'name'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}")])
                ->all(),
            'categories' => MaterialCategory::query()->orderBy('name')->limit(500)
                ->get(['id', 'name'])
                ->map(fn (MaterialCategory $c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            'status_options' => $this->statusOptions(),
            'budgets' => $project->budgets()->orderByDesc('version')->get(['id', 'version', 'status'])
                ->map(fn ($b) => [
                    'value' => $b->id,
                    'label' => 'v'.$b->version.' ('.$b->status->value.')',
                ])
                ->all(),
            'boqs' => $project->boqs()->orderByDesc('version')->get(['id', 'boq_number', 'version', 'is_current'])
                ->map(fn ($b) => [
                    'value' => $b->id,
                    'label' => $b->boq_number.' v'.$b->version.($b->is_current ? ' (current)' : ''),
                ])
                ->all(),
            'can' => [
                'export' => true,
                'view_costs' => $canCosts,
            ],
        ]);
    }

    public function show(Request $request, Project $project, Material $material): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation') || $request->user()->can('reports.view'), 403);
        abort_unless((int) $material->company_id === (int) $project->company_id, 404);

        $filters = $this->filters($request);
        $detail = $this->control->detail($project, $material->id, $filters);
        $canCosts = $request->user()->can('boq.view_costs');
        if (! $canCosts) {
            if (isset($detail['summary']) && is_array($detail['summary'])) {
                $redacted = $this->control->redactCosts(['rows' => [$detail['summary']], 'summary' => $detail['summary']]);
                $detail['summary'] = $redacted['rows'][0] ?? null;
            }
            foreach ($detail['estimate_sources'] ?? [] as $i => $src) {
                unset($detail['estimate_sources'][$i]['rate'], $detail['estimate_sources'][$i]['amount']);
            }
            foreach ($detail['budget_lines'] ?? [] as $i => $line) {
                unset($detail['budget_lines'][$i]['rate'], $detail['budget_lines'][$i]['amount']);
            }
            foreach ($detail['ledger'] ?? [] as $i => $txn) {
                unset($detail['ledger'][$i]['unit_cost'], $detail['ledger'][$i]['value']);
            }
        }

        return Inertia::render('Boq/MaterialControlShow', [
            'project' => ProjectHeader::for($project),
            'material' => [
                'id' => $material->id,
                'code' => $material->code,
                'name' => $material->name,
            ],
            'detail' => $detail,
            'can' => [
                'view_costs' => $canCosts,
            ],
        ]);
    }

    public function export(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_reconciliation') || $request->user()->can('reports.view'), 403);

        $filters = $this->filters($request);
        $filters['page'] = 1;
        $filters['per_page'] = 10000;
        $result = $this->control->report($project, $filters);
        $canCosts = $request->user()->can('boq.view_costs');
        if (! $canCosts) {
            $result = $this->control->redactCosts($result);
        }

        $name = 'material-control-'.$project->code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new ProjectMaterialControlExport($project, $result['rows'], $filters, $canCosts),
            $name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'material_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:100'],
            'budget_version_id' => ['nullable', 'integer'],
            'boq_id' => ['nullable', 'integer'],
            'over_consumption' => ['nullable', 'boolean'],
            'unestimated_only' => ['nullable', 'boolean'],
            'as_of_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @return list<array{value: ?string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => null, 'label' => '— All —'],
            ['value' => 'On Track', 'label' => 'On Track'],
            ['value' => 'Near Limit', 'label' => 'Near Limit'],
            ['value' => 'Over Consumption', 'label' => 'Over Consumption'],
            ['value' => 'Unestimated Material', 'label' => 'Unestimated Material'],
            ['value' => 'No Consumption Yet', 'label' => 'No Consumption Yet'],
            ['value' => 'Budget Sync Required', 'label' => 'Budget Sync Required'],
        ];
    }
}
