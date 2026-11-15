<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Boq\Concerns\RequiresAdvancedBoqCalculations;
use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Services\Boq\AdvancedBoqWorkbookImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvancedBoqWorkbookImportController extends Controller
{
    use RequiresAdvancedBoqCalculations;

    public function __construct(private readonly AdvancedBoqWorkbookImportService $imports) {}

    public function create(Request $request, Project $project, ?Boq $boq = null): Response
    {
        $this->requireAdvancedBoqCalculations();
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        if ($boq !== null) {
            abort_unless((int) $boq->project_id === (int) $project->id, 404);
            Gate::authorize('import', $boq);
        }

        return Inertia::render('Boq/WorkbookImport', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'boq' => $boq ? [
                'id' => $boq->id,
                'boq_number' => $boq->boq_number,
                'version' => $boq->version,
                'status' => $boq->status->value,
                'title' => $boq->title,
                'is_editable' => $boq->isEditable(),
            ] : null,
            'modes' => [
                ['value' => 'new', 'label' => 'Create New Advanced BOQ'],
                ['value' => 'existing', 'label' => 'Import Into Existing Draft'],
                ['value' => 'revision', 'label' => 'Import Into New Revision'],
            ],
        ]);
    }

    public function upload(Request $request, Project $project): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) config('uploads.import_max_kb', 10240),
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/zip',
            ],
            'mode' => ['required', Rule::in(['new', 'existing', 'revision'])],
            'boq_id' => ['nullable', 'integer', 'exists:boqs,id'],
        ]);

        $boq = null;
        if (! empty($data['boq_id'])) {
            $boq = Boq::query()->where('project_id', $project->id)->whereKey($data['boq_id'])->firstOrFail();
            if ($data['mode'] === 'existing') {
                Gate::authorize('import', $boq);
            }
            if ($data['mode'] === 'revision') {
                Gate::authorize('revise', $boq);
            }
        }

        $result = $this->imports->upload(
            $project,
            $request->user(),
            $request->file('file'),
            $boq,
            $data['mode'],
        );

        return redirect()
            ->route('projects.boqs.workbook-import.show', [$project, $result['import']->id])
            ->with('success', 'Workbook uploaded. Review detected sheets.');
    }

    public function show(Request $request, Project $project, DataImport $import): Response
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertImport($project, $import);
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        return Inertia::render('Boq/WorkbookImport', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'importBatch' => [
                'id' => $import->id,
                'uuid' => $import->uuid,
                'status' => $import->status->value,
                'original_filename' => $import->original_filename,
                'context' => $import->context,
                'summary' => $import->summary,
            ],
            'detection' => $import->summary['detection'] ?? null,
            'modes' => [
                ['value' => 'new', 'label' => 'Create New Advanced BOQ'],
                ['value' => 'existing', 'label' => 'Import Into Existing Draft'],
                ['value' => 'revision', 'label' => 'Import Into New Revision'],
            ],
        ]);
    }

    public function preview(Request $request, Project $project, DataImport $import): RedirectResponse|JsonResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertImport($project, $import);
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        $options = $request->validate([
            'import_schedule' => ['sometimes', 'boolean'],
            'replace_previous' => ['sometimes', 'boolean'],
            'material_map' => ['sometimes', 'array'],
        ]);

        $preview = $this->imports->preview($import, $options);

        if ($request->wantsJson()) {
            return response()->json($preview);
        }

        return redirect()
            ->route('projects.boqs.workbook-import.show', [$project, $import])
            ->with('success', 'Preview ready. Review validation before confirming.');
    }

    public function confirm(Request $request, Project $project, DataImport $import): RedirectResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertImport($project, $import);
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);
        abort_unless($request->user()->can('boq.view_costs'), 403);

        $options = $request->validate([
            'import_schedule' => ['sometimes', 'boolean'],
            'replace_previous' => ['sometimes', 'boolean'],
            'create_missing_materials' => ['sometimes', 'boolean'],
        ]);

        $result = $this->imports->confirm($import, $project, $request->user(), $options);

        return redirect()
            ->route('projects.boqs.show', [$project, $result['result']['boq_id']])
            ->with('success', 'Advanced costing workbook imported. Budget Sync Required — run Sync from BOQ separately.');
    }

    public function report(Request $request, Project $project, DataImport $import): JsonResponse|StreamedResponse
    {
        $this->requireAdvancedBoqCalculations();
        $this->assertImport($project, $import);
        Gate::authorize('viewAny', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        $report = $this->imports->report($import);

        return response()->json($report);
    }

    private function assertImport(Project $project, DataImport $import): void
    {
        abort_unless($import->module === AdvancedBoqWorkbookImportService::MODULE, 404);
        abort_unless((int) $import->project_id === (int) $project->id, 404);
    }
}
