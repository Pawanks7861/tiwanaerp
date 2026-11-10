<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportStatus;
use App\Http\Controllers\Controller;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Services\Imports\DataImportService;
use App\Support\Imports\ImportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportController extends Controller
{
    public function __construct(private readonly DataImportService $imports) {}

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
            'context.reimport_action' => ['nullable', 'string', 'in:additional,replace'],
            'context.header_row' => ['nullable', 'integer', 'min:1'],
            'sheet_index' => ['nullable', 'integer', 'min:0'],
            'header_row' => ['nullable', 'integer', 'min:1'],
        ]);

        $project = isset($data['project_id'])
            ? Project::query()->findOrFail($data['project_id'])
            : null;

        $context = $data['context'] ?? [];
        if (isset($data['sheet_index'])) {
            $context['sheet_index'] = $data['sheet_index'];
        }
        if (isset($data['header_row'])) {
            $context['header_row'] = $data['header_row'];
        }

        $result = $this->imports->upload(
            $request->user(),
            $data['module'],
            $request->file('file'),
            $project,
            $context,
        );

        return response()->json([
            'import' => $this->payload($result['import']),
            'headers' => $result['headers'],
            'raw_headers' => $result['raw_headers'] ?? [],
            'mapping' => $result['mapping'],
            'fields' => $result['fields'],
            'modes' => $result['modes'],
            'instructions' => $result['instructions'],
            'sheets' => $result['sheets'] ?? [],
            'suggested_sheet_index' => $result['suggested_sheet_index'] ?? null,
            'header_row' => $result['header_row'] ?? null,
            'suggested_header_row' => $result['suggested_header_row'] ?? null,
            'prior_import' => $result['prior_import'] ?? null,
        ]);
    }

    public function selectSheet(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('view', $import);

        $data = $request->validate([
            'sheet_index' => ['required', 'integer', 'min:0'],
            'header_row' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->imports->selectSheet(
            $import,
            (int) $data['sheet_index'],
            $request->user(),
            isset($data['header_row']) ? (int) $data['header_row'] : null,
        );

        return response()->json([
            'import' => $this->payload($result['import']),
            'headers' => $result['headers'],
            'raw_headers' => $result['raw_headers'] ?? [],
            'mapping' => $result['mapping'],
            'sheets' => $result['sheets'],
            'header_row' => $result['header_row'] ?? null,
            'suggested_header_row' => $result['suggested_header_row'] ?? null,
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

    public function confirm(Request $request, DataImport $import): JsonResponse
    {
        Gate::authorize('view', $import);

        $data = $request->validate([
            'reimport_action' => ['nullable', 'string', 'in:additional,replace'],
        ]);
        if (! empty($data['reimport_action'])) {
            $context = $import->context ?? [];
            $context['reimport_action'] = $data['reimport_action'];
            $import->forceFill(['context' => $context])->save();
        }

        $import = $this->imports->confirm($import, $request->user());

        return response()->json(['import' => $this->payload($import)]);
    }

    public function downloadFile(Request $request, DataImport $import): StreamedResponse
    {
        Gate::authorize('view', $import);
        abort_unless($import->path, 404);

        return Storage::disk($import->disk)->download(
            $import->path,
            $import->original_filename ?: 'import-'.$import->uuid,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DataImport $import): array
    {
        $import->loadMissing(['uploader:id,name', 'project:id,code,name']);
        $context = $this->publicContext($import->context ?? []);
        $status = $import->status instanceof ImportStatus ? $import->status : ImportStatus::tryFrom((string) $import->status);

        return [
            'id' => $import->id,
            'uuid' => $import->uuid,
            'module' => $import->module,
            'module_label' => ImportCatalog::get($import->module)->label(),
            'mode' => $import->mode?->value ?? $import->mode,
            'status' => $status?->value ?? $import->status,
            'status_label' => $status?->label() ?? $import->status?->label(),
            'step' => $this->step($status),
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
            'mapping' => $import->mapping,
            'context' => $context,
            'sheet_index' => $context['sheet_index'] ?? null,
            'header_row' => $context['header_row'] ?? null,
            'boq_id' => $context['boq_id'] ?? null,
            'failure_message' => $import->failure_message,
            'has_error_report' => (bool) $import->error_report_path,
            'can_confirm' => $status === ImportStatus::Ready && (int) ($import->valid_rows ?? 0) > 0,
            'uploaded_by' => $import->uploader?->only(['id', 'name']),
            'project' => $import->project?->only(['id', 'code', 'name']),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
            'updated_at' => $import->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function publicContext(array $context): array
    {
        unset($context['path'], $context['disk'], $context['storage_path'], $context['absolute_path']);

        return $context;
    }

    private function step(?ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::Uploaded => 'uploaded',
            ImportStatus::Validating => 'validating',
            ImportStatus::Ready => 'ready',
            ImportStatus::Processing => 'processing',
            ImportStatus::Completed, ImportStatus::CompletedWithErrors => 'completed',
            ImportStatus::Failed => 'failed',
            ImportStatus::Cancelled => 'cancelled',
            default => 'unknown',
        };
    }
}
