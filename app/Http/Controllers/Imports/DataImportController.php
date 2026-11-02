<?php

namespace App\Http\Controllers\Imports;

use App\Enums\Imports\ImportMode;
use App\Http\Controllers\Controller;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Services\Imports\DataImportService;
use App\Services\Imports\ImportTemplateService;
use App\Support\Imports\ImportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportController extends Controller
{
    public function __construct(
        private readonly DataImportService $imports,
        private readonly ImportTemplateService $templates,
    ) {}

    public function history(Request $request): Response
    {
        Gate::authorize('viewAny', DataImport::class);

        $user = $request->user();
        $query = DataImport::query()
            ->with(['uploader:id,name', 'project:id,code,name'])
            ->latest('id');

        if (! $user->isSuperAdmin() && ! $user->can('admin.users.manage')) {
            $query->where('uploaded_by', $user->id);
        }

        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }

        $imports = $query->paginate(25)->withQueryString()->through(fn (DataImport $import) => $this->payload($import));

        return Inertia::render('Imports/History', [
            'imports' => $imports,
            'modules' => ImportCatalog::availableFor($user),
            'filters' => ['module' => $request->query('module')],
        ]);
    }

    public function template(Request $request, string $module): BinaryFileResponse
    {
        Gate::authorize('create', DataImport::class);

        $data = $request->validate([
            'format' => ['nullable', 'string', Rule::in(['xlsx', 'csv'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $project = isset($data['project_id'])
            ? Project::query()->findOrFail($data['project_id'])
            : null;

        return $this->templates->download(
            $request->user(),
            $module,
            $data['format'] ?? 'xlsx',
            $project,
        );
    }

    public function meta(Request $request, string $module): JsonResponse
    {
        $definition = $this->imports->definition($module);
        abort_unless($definition->authorize($request->user()), 403);

        $projectId = $request->integer('project_id') ?: null;

        return response()->json([
            'key' => $definition->key(),
            'label' => $definition->label(),
            'fields' => $definition->fieldsPayload(),
            'modes' => $definition->modeOptions(),
            'instructions' => $definition->instructions(),
            'requires_project' => $definition->requiresProject(),
            'template_filename' => $definition->templateFilename(),
            'template_url' => route('imports.template', array_filter([
                'module' => $module,
                'format' => 'xlsx',
                'project_id' => $projectId,
            ])),
            'template_csv_url' => route('imports.template', array_filter([
                'module' => $module,
                'format' => 'csv',
                'project_id' => $projectId,
            ])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', DataImport::class);

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(array_keys(ImportCatalog::all()))],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('imports.max_upload_kb', 5120),
                'mimes:'.implode(',', config('imports.mimes')),
            ],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'context' => ['nullable', 'array'],
            'context.boq_id' => ['nullable', 'integer', 'exists:boqs,id'],
            'context.material_request_id' => ['nullable', 'integer', 'exists:material_requests,id'],
            'context.stock_adjustment_id' => ['nullable', 'integer', 'exists:stock_adjustments,id'],
        ]);

        $project = isset($data['project_id'])
            ? Project::query()->findOrFail($data['project_id'])
            : null;

        $result = $this->imports->upload(
            $request->user(),
            $data['module'],
            $request->file('file'),
            $project,
            $data['context'] ?? [],
        );

        return response()->json([
            'import' => $this->payload($result['import']),
            'headers' => $result['headers'],
            'mapping' => $result['mapping'],
            'fields' => $result['fields'],
            'modes' => $result['modes'],
            'instructions' => $result['instructions'],
        ]);
    }

    public function show(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('view', $import);
        $preview = $this->imports->preview($import);

        return response()->json([
            'import' => $this->payload($import),
            'summary' => $preview['summary'],
            'preview' => $preview['preview'],
        ]);
    }

    public function validateMapping(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('view', $import);

        $data = $request->validate([
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ]);

        $import = $this->imports->mapAndValidate(
            $import,
            $data['mapping'],
            ImportMode::from($data['mode']),
            $request->user(),
        );

        $preview = $this->imports->preview($import);

        return response()->json([
            'import' => $this->payload($import),
            'summary' => $preview['summary'],
            'preview' => $preview['preview'],
        ]);
    }

    public function confirm(Request $request, DataImport $import): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $import);
        $import = $this->imports->confirm($import, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['import' => $this->payload($import)]);
        }

        return back()->with('success', $this->successMessage($import));
    }

    public function errorReport(Request $request, DataImport $import): StreamedResponse
    {
        Gate::authorize('downloadErrorReport', $import);
        abort_unless($import->error_report_path, 404);

        return Storage::disk($import->disk)->download(
            $import->error_report_path,
            'import-errors-'.$import->uuid.'.xlsx',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DataImport $import): array
    {
        return [
            'id' => $import->id,
            'uuid' => $import->uuid,
            'module' => $import->module,
            'module_label' => ImportCatalog::get($import->module)->label(),
            'mode' => $import->mode?->value ?? $import->mode,
            'status' => $import->status?->value ?? $import->status,
            'status_label' => $import->status?->label(),
            'original_filename' => $import->original_filename,
            'size_bytes' => $import->size_bytes,
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->valid_rows,
            'invalid_rows' => $import->invalid_rows,
            'duplicate_rows' => $import->duplicate_rows,
            'create_count' => $import->create_count,
            'update_count' => $import->update_count,
            'skip_count' => $import->skip_count,
            'fail_count' => $import->fail_count,
            'summary' => $import->summary,
            'failure_message' => $import->failure_message,
            'has_error_report' => (bool) $import->error_report_path,
            'uploaded_by' => $import->uploader?->only(['id', 'name']),
            'project' => $import->project?->only(['id', 'code', 'name']),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function successMessage(DataImport $import): string
    {
        return "Import finished: {$import->create_count} created, {$import->update_count} updated, {$import->skip_count} skipped.";
    }
}
