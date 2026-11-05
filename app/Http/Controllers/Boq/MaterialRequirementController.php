<?php

namespace App\Http\Controllers\Boq;

use App\Exports\MaterialRequirementExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Services\Boq\MaterialRequirementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaterialRequirementController extends Controller
{
    public function __construct(private readonly MaterialRequirementService $requirements) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);

        $filters = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = $this->requirements->projectSummary(
            $project,
            isset($filters['material_id']) ? (int) $filters['material_id'] : null,
        );

        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $needle = mb_strtolower($term);
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => str_contains(mb_strtolower($r['material_code'].' '.$r['material_name']), $needle)
            ));
        }

        return Inertia::render('Boq/MaterialRequirement', [
            'project' => ProjectHeader::for($project),
            'rows' => $rows,
            'filters' => $filters,
            'materials' => Material::query()->active()->orderBy('name')->limit(2000)
                ->get(['id', 'code', 'name'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}")])
                ->all(),
            'formula_note' => 'Remaining Requirement = max(0, Required Qty − Approved GRN Accepted Qty). PO / MR / Issued / Stock are reference only. For steel materials mapped from Approved BBS, BBS is authoritative (Material Norm for those materials is ignored to prevent double counting).',
            'can' => [
                'create_mr' => $request->user()->can('material_requests.create'),
                'export' => $request->user()->can('boq.export') || $request->user()->can('reports.export') || $request->user()->can('boq.view_material_requirements'),
            ],
        ]);
    }

    public function show(Request $request, Project $project, Material $material): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);
        abort_unless((int) $material->company_id === (int) $project->company_id, 404);

        $unit = $request->string('unit')->toString() ?: null;
        $sources = $this->requirements->materialDrillDown($project, $material->id, $unit);
        $summary = collect($this->requirements->projectSummary($project, $material->id))
            ->first(fn ($r) => $unit === null || mb_strtolower($r['unit']) === mb_strtolower($unit));

        return Inertia::render('Boq/MaterialRequirementShow', [
            'project' => ProjectHeader::for($project),
            'material' => [
                'id' => $material->id,
                'code' => $material->code,
                'name' => $material->name,
            ],
            'summary' => $summary,
            'sources' => $sources,
            'unit' => $unit,
        ]);
    }

    public function export(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);

        $rows = $this->requirements->projectSummary($project);
        $name = 'material-requirement-'.$project->code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new MaterialRequirementExport($project, $rows), $name);
    }

    public function createMr(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_material_requirements'), 403);
        abort_unless($request->user()->can('material_requests.create'), 403);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.material_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.boq_item_id' => ['nullable', 'integer'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $prefill = [];
        foreach ($data['lines'] as $line) {
            $material = Material::query()->with('unit:id,symbol')->whereKey($line['material_id'])
                ->where('company_id', $project->company_id)->firstOrFail();
            $prefill[] = [
                'material_id' => $material->id,
                'unit_id' => $material->unit_id,
                'quantity' => (string) $line['quantity'],
                'boq_item_id' => $line['boq_item_id'] ?? null,
                'remarks' => $line['remarks'] ?? 'From BOQ Material Requirement',
            ];
        }

        return redirect()
            ->route('projects.material-requests.create', $project)
            ->with('mr_requirement_prefill', $prefill)
            ->with('success', 'Review and save the Material Request. Suggested quantities came from Material Requirement planning.');
    }
}
