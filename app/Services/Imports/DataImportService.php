<?php

namespace App\Services\Imports;

use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportStatus;
use App\Exports\Imports\ImportErrorReportExport;
use App\Jobs\Imports\ProcessDataImportJob;
use App\Jobs\Imports\ValidateDataImportJob;
use App\Models\Imports\DataImport;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Imports\ImportCatalog;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class DataImportService
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ColumnMapper $mapper,
        private readonly CurrentCompany $company,
    ) {}

    public function definition(string $module): ImportDefinition
    {
        return ImportCatalog::get($module);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{import: DataImport, headers: list<string>, mapping: array<string, string|null>, fields: list<array<string, mixed>>, modes: list<array{value: string, label: string}>}
     */
    public function upload(User $user, string $module, UploadedFile $file, ?Project $project = null, array $context = []): array
    {
        $definition = $this->definition($module);
        if (! $definition->authorize($user, $project)) {
            abort(403);
        }
        if ($definition->requiresProject() && $project === null) {
            throw ValidationException::withMessages(['project_id' => 'This import requires a project.']);
        }

        $uuid = (string) Str::uuid();
        $stored = $this->reader->storeUpload($file, (int) $this->company->id(), $uuid);
        $sheetNames = $this->reader->sheetNames($stored['disk'], $stored['path']);
        $suggestedSheet = $this->reader->suggestSheetIndex($sheetNames);
        $sheetIndex = array_key_exists('sheet_index', $context)
            ? max(0, (int) $context['sheet_index'])
            : (int) ($suggestedSheet ?? 0);
        if ($sheetIndex >= count($sheetNames)) {
            $sheetIndex = 0;
        }
        $headerRow = isset($context['header_row']) ? max(1, (int) $context['header_row']) : null;
        $context['sheet_index'] = $sheetIndex;
        $sheet = $this->reader->read($stored['disk'], $stored['path'], $sheetIndex, $headerRow);
        $context['header_row'] = $sheet['header_row'];
        $context['suggested_header_row'] = $sheet['suggested_header_row'];
        $context['sheet_name'] = $sheet['sheet_name'];
        $context['suggested_sheet_index'] = $suggestedSheet;
        $mapping = $this->mapper->suggest($definition, $sheet['headers']);

        $import = new DataImport;
        $import->fill([
            'uuid' => $uuid,
            'project_id' => $project?->id,
            'context' => $context,
            'module' => $module,
            'mode' => $definition->modes()[0]->value,
            'status' => ImportStatus::Uploaded,
            'original_filename' => $stored['original_filename'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size_bytes'],
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'mapping' => $mapping,
            'uploaded_by' => $user->id,
            'total_rows' => count($sheet['rows']),
        ])->save();

        return [
            'import' => $import,
            'headers' => $sheet['headers'],
            'raw_headers' => $sheet['raw_headers'],
            'mapping' => $mapping,
            'fields' => $definition->fieldsPayload(),
            'modes' => $definition->modeOptions(),
            'instructions' => $definition->instructions(),
            'sheets' => $sheetNames,
            'suggested_sheet_index' => $suggestedSheet,
            'header_row' => $sheet['header_row'],
            'suggested_header_row' => $sheet['suggested_header_row'],
            'prior_import' => $this->priorBoqImport($import, $context),
        ];
    }

    /**
     * @return array{import: DataImport, headers: list<string>, raw_headers: list<string>, mapping: array<string, string|null>, sheets: list<string>, header_row: int, suggested_header_row: int}
     */
    public function selectSheet(DataImport $import, int $sheetIndex, User $user, ?int $headerRow = null): array
    {
        $definition = $this->definition($import->module);
        if (! $definition->authorize($user, $import->project)) {
            abort(403);
        }

        $sheetNames = $this->reader->sheetNames($import->disk, $import->path);
        if ($sheetIndex < 0 || $sheetIndex >= count($sheetNames)) {
            throw ValidationException::withMessages(['sheet_index' => 'Choose a valid worksheet.']);
        }

        $context = $import->context ?? [];
        $context['sheet_index'] = $sheetIndex;
        if ($headerRow !== null) {
            $context['header_row'] = max(1, $headerRow);
        }

        $sheet = $this->reader->read(
            $import->disk,
            $import->path,
            $sheetIndex,
            isset($context['header_row']) ? (int) $context['header_row'] : null,
        );
        $context['header_row'] = $sheet['header_row'];
        $context['suggested_header_row'] = $sheet['suggested_header_row'];
        $context['sheet_name'] = $sheet['sheet_name'];
        $mapping = $this->mapper->suggest($definition, $sheet['headers']);

        $import->forceFill([
            'context' => $context,
            'mapping' => $mapping,
            'status' => ImportStatus::Uploaded,
            'validation_path' => null,
            'error_report_path' => null,
            'summary' => null,
            'total_rows' => count($sheet['rows']),
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
            'create_count' => 0,
            'update_count' => 0,
            'skip_count' => 0,
            'fail_count' => 0,
            'failure_message' => null,
        ])->save();

        return [
            'import' => $import->fresh(),
            'headers' => $sheet['headers'],
            'raw_headers' => $sheet['raw_headers'],
            'mapping' => $mapping,
            'sheets' => $sheetNames,
            'header_row' => $sheet['header_row'],
            'suggested_header_row' => $sheet['suggested_header_row'],
        ];
    }

    /**
     * @param  array<string, string|null>  $mapping
     */
    public function mapAndValidate(DataImport $import, array $mapping, ImportMode $mode, User $user): DataImport
    {
        $definition = $this->definition($import->module);
        if (! $definition->authorize($user, $import->project)) {
            abort(403);
        }
        if (! in_array($mode, $definition->modes(), true)) {
            throw ValidationException::withMessages(['mode' => 'This import mode is not allowed for '.$definition->label().'.']);
        }

        foreach ($definition->fields() as $field) {
            if ($field->required && blank($mapping[$field->key] ?? null)) {
                throw ValidationException::withMessages([
                    "mapping.{$field->key}" => "Please map {$field->label} before continuing.",
                ]);
            }
        }

        $import->forceFill([
            'mapping' => $mapping,
            'mode' => $mode,
            'status' => ImportStatus::Validating,
            'failure_message' => null,
        ])->save();

        $threshold = (int) config('imports.queue_threshold_rows', 200);
        if ($import->total_rows >= $threshold && config('queue.default') !== 'sync') {
            ValidateDataImportJob::dispatch($import->id)->onQueue((string) config('imports.queue', 'imports'));

            return $import->fresh();
        }

        return $this->runValidation($import);
    }

    public function runValidation(DataImport $import): DataImport
    {
        $definition = $this->definition($import->module);
        $headerRow = (int) (($import->context ?? [])['header_row'] ?? 0) ?: null;
        $sheet = $this->reader->read($import->disk, $import->path, $this->sheetIndex($import), $headerRow);
        $contextMeta = array_merge($import->context ?? [], [
            'import_id' => $import->id,
            'sheet_name' => $sheet['sheet_name'],
            'header_row' => $sheet['header_row'],
        ]);
        $import->forceFill([
            'context' => array_merge($import->context ?? [], [
                'sheet_name' => $sheet['sheet_name'],
                'header_row' => $sheet['header_row'],
                'suggested_header_row' => $sheet['suggested_header_row'],
            ]),
        ])->save();

        $context = new ImportContext($import->company, $import->uploader, $import->project, $contextMeta);
        $mode = $import->mode instanceof ImportMode ? $import->mode : ImportMode::from($import->mode);

        $rows = [];
        $seenKeys = [];
        $summary = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'create' => 0,
            'update' => 0,
            'skip' => 0,
            'warnings' => 0,
            'boq_value' => 0.0,
            'sheet_name' => $sheet['sheet_name'],
            'header_row' => $sheet['header_row'],
        ];

        foreach ($sheet['rows'] as $index => $raw) {
            $line = $sheet['header_row'] + $index + 1;
            $mapped = $this->mapper->apply($import->mapping ?? [], $raw);
            $summary['total']++;

            try {
                $uniqueKey = $definition->uniqueKey();
                if ($uniqueKey && filled($mapped[$uniqueKey] ?? null)) {
                    $fingerprint = strtolower(trim((string) $mapped[$uniqueKey]));
                    if (isset($seenKeys[$fingerprint])) {
                        throw ValidationException::withMessages([
                            $uniqueKey => ["Duplicate {$uniqueKey} \"{$mapped[$uniqueKey]}\" also appears on row {$seenKeys[$fingerprint]}."],
                        ]);
                    }
                    $seenKeys[$fingerprint] = $line;
                }

                $prepared = $definition->prepareRow($mapped, $mode, $context);
                $prepared['source_row'] = $line;
                $action = $prepared['action'];

                if ($action === 'skip') {
                    $summary['skip']++;
                    $rows[] = [
                        'row' => $line,
                        'sheet' => $sheet['sheet_name'],
                        'status' => 'skipped',
                        'action' => 'skip',
                        'errors' => [],
                        'preview' => $prepared['preview'],
                        'prepared' => $prepared,
                    ];

                    continue;
                }

                $isDuplicate = $action === 'update';
                if ($isDuplicate) {
                    $summary['duplicate']++;
                }
                $summary['valid']++;
                $summary[$action]++;
                $warnings = $prepared['warnings'] ?? ($prepared['preview']['warnings'] ?? []);
                if ($warnings !== []) {
                    $summary['warnings']++;
                }
                if (isset($prepared['preview']['amount'])) {
                    $summary['boq_value'] += (float) $prepared['preview']['amount'];
                }

                $rows[] = [
                    'row' => $line,
                    'sheet' => $sheet['sheet_name'],
                    'status' => $warnings !== [] ? 'warning' : ($isDuplicate ? 'duplicate' : 'valid'),
                    'action' => $action,
                    'errors' => [],
                    'preview' => $prepared['preview'],
                    'prepared' => $prepared,
                ];
            } catch (ValidationException $e) {
                $summary['invalid']++;
                $messages = [];
                foreach ($e->errors() as $field => $fieldErrors) {
                    foreach ($fieldErrors as $message) {
                        $messages[] = ['field' => $field, 'message' => $message];
                    }
                }
                $rows[] = [
                    'row' => $line,
                    'sheet' => $sheet['sheet_name'],
                    'status' => 'invalid',
                    'action' => null,
                    'errors' => $messages,
                    'preview' => [
                        'code' => $mapped['item_code'] ?? null,
                        'name' => $mapped['name'] ?? null,
                        ...$mapped,
                    ],
                    'prepared' => null,
                ];
            } catch (Throwable $e) {
                report($e);
                $summary['invalid']++;
                $rows[] = [
                    'row' => $line,
                    'sheet' => $sheet['sheet_name'],
                    'status' => 'invalid',
                    'action' => null,
                    'errors' => [['field' => 'row', 'message' => 'This row could not be validated.']],
                    'preview' => $mapped,
                    'prepared' => null,
                ];
            }
        }

        [$rows, $summary] = $definition->consolidate($rows, $summary, $context);

        $validationPath = $this->writeJson($import, 'validation.json', ['rows' => $rows, 'summary' => $summary]);
        $errorPath = null;
        $invalid = array_values(array_filter($rows, fn ($r) => $r['status'] === 'invalid'));
        if ($invalid !== []) {
            $errorPath = $this->writeErrorReport($import, $invalid);
        }

        $import->forceFill([
            'status' => ImportStatus::Ready,
            'validation_path' => $validationPath,
            'error_report_path' => $errorPath,
            'summary' => $summary,
            'total_rows' => $summary['total'],
            'valid_rows' => $summary['valid'],
            'invalid_rows' => $summary['invalid'],
            'duplicate_rows' => $summary['duplicate'],
            'create_count' => $summary['create'],
            'update_count' => $summary['update'],
            'skip_count' => $summary['skip'],
            'fail_count' => $summary['invalid'],
        ])->save();

        return $import->fresh();
    }

    public function confirm(DataImport $import, User $user): DataImport
    {
        $definition = $this->definition($import->module);
        if (! $definition->authorize($user, $import->project)) {
            abort(403);
        }
        if ($import->status !== ImportStatus::Ready) {
            throw ValidationException::withMessages(['import' => 'Validate the file before confirming the import.']);
        }
        if (($import->valid_rows ?? 0) < 1) {
            throw ValidationException::withMessages(['import' => 'There are no valid rows to import. Fix the errors and upload again.']);
        }

        if ($definition instanceof \App\Support\Imports\Definitions\BoqItemsImportDefinition) {
            $definition->assertReimportAllowed($this->importContext($import), $import);
            $action = (string) (($import->context ?? [])['reimport_action'] ?? '');
            if ($action === 'replace') {
                $previous = DataImport::query()
                    ->where('module', 'boq.items')
                    ->where('company_id', $import->company_id)
                    ->where('id', '!=', $import->id)
                    ->where('original_filename', $import->original_filename)
                    ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::CompletedWithErrors->value])
                    ->where('context->boq_id', (int) (($import->context ?? [])['boq_id'] ?? 0))
                    ->latest('id')
                    ->first();
                if ($previous) {
                    $ctx = $import->context ?? [];
                    $ctx['replace_import_id'] = $previous->id;
                    $import->forceFill(['context' => $ctx])->save();
                }
            }
        }

        $import->forceFill([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
            'finished_at' => null,
            'failure_message' => null,
        ])->save();

        $threshold = (int) config('imports.queue_threshold_rows', 200);
        if ($import->total_rows >= $threshold && config('queue.default') !== 'sync') {
            ProcessDataImportJob::dispatch($import->id)->onQueue((string) config('imports.queue', 'imports'));

            return $import->fresh();
        }

        return $this->runProcess($import);
    }

    public function runProcess(DataImport $import): DataImport
    {
        $definition = $this->definition($import->module);
        $payload = $this->readJson($import, $import->validation_path);
        $context = $this->importContext($import);
        $mode = $import->mode instanceof ImportMode ? $import->mode : ImportMode::from($import->mode);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $processErrors = [];

        try {
            DB::transaction(function () use ($definition, $payload, $mode, $context, &$created, &$updated, &$skipped, &$failed, &$processErrors) {
                foreach ($payload['rows'] as $row) {
                    if (($row['status'] ?? '') === 'invalid') {
                        continue;
                    }
                    if (($row['action'] ?? '') === 'skip' || ($row['prepared']['action'] ?? '') === 'skip') {
                        $skipped++;

                        continue;
                    }
                    if (! isset($row['prepared'])) {
                        continue;
                    }

                    try {
                        $result = $definition->commitRow($row['prepared'], $mode, $context);
                        match ($result) {
                            'created' => $created++,
                            'updated' => $updated++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $processErrors[] = [
                            'row' => $row['row'],
                            'status' => 'invalid',
                            'errors' => [['field' => 'row', 'message' => $e instanceof ValidationException
                                ? collect($e->errors())->flatten()->first()
                                : 'This row could not be saved.']],
                        ];
                    }
                }

                if ($failed > 0) {
                    throw ValidationException::withMessages([
                        'import' => "{$failed} row(s) failed while saving. Nothing was committed.",
                    ]);
                }

                $definition->afterCommit($context);
            });
        } catch (ValidationException $e) {
            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_message' => collect($e->errors())->flatten()->first(),
                'fail_count' => $failed,
                'finished_at' => now(),
            ])->save();

            if ($processErrors !== []) {
                $import->forceFill([
                    'error_report_path' => $this->writeErrorReport($import, $processErrors),
                ])->save();
            }

            throw $e;
        } catch (Throwable $e) {
            report($e);
            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_message' => 'The import failed. Try again or contact support.',
                'finished_at' => now(),
            ])->save();
            throw $e;
        }

        $status = $failed > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed;
        $import->forceFill([
            'status' => $status,
            'create_count' => $created,
            'update_count' => $updated,
            'skip_count' => $skipped,
            'fail_count' => $failed,
            'finished_at' => now(),
            'summary' => array_merge($import->summary ?? [], [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
            ]),
        ])->save();

        return $import->fresh();
    }

    /**
     * @return array{summary: array<string, mixed>, preview: list<array<string, mixed>>}
     */
    public function preview(DataImport $import): array
    {
        $limit = (int) config('imports.preview_rows', 30);
        if (! $import->validation_path) {
            return ['summary' => $import->summary ?? [], 'preview' => []];
        }

        $payload = $this->readJson($import, $import->validation_path);
        $preview = array_slice($payload['rows'] ?? [], 0, $limit);
        $preview = array_map(function (array $row) {
            unset($row['prepared']);

            return $row;
        }, $preview);

        return [
            'summary' => $payload['summary'] ?? $import->summary ?? [],
            'preview' => $preview,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $invalidRows
     */
    private function writeErrorReport(DataImport $import, array $invalidRows): string
    {
        $relative = $this->directory($import).'/errors.xlsx';
        Excel::store(new ImportErrorReportExport($invalidRows), $relative, $import->disk);

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function priorBoqImport(DataImport $import, array $context): ?array
    {
        if ($import->module !== 'boq.items') {
            return null;
        }
        $boqId = (int) ($context['boq_id'] ?? 0);
        if ($boqId < 1) {
            return null;
        }

        $previous = DataImport::query()
            ->where('module', 'boq.items')
            ->where('company_id', $import->company_id)
            ->where('id', '!=', $import->id)
            ->where('original_filename', $import->original_filename)
            ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::CompletedWithErrors->value])
            ->where('context->boq_id', $boqId)
            ->latest('id')
            ->first();

        if (! $previous) {
            return null;
        }

        return [
            'id' => $previous->id,
            'original_filename' => $previous->original_filename,
            'finished_at' => $previous->finished_at?->toIso8601String(),
            'message' => 'This file has already been imported into this BOQ.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(DataImport $import, string $filename, array $payload): string
    {
        $relative = $this->directory($import).'/'.$filename;
        Storage::disk($import->disk)->put($relative, json_encode($payload, JSON_THROW_ON_ERROR));

        return $relative;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(DataImport $import, ?string $path): array
    {
        if (! $path || ! Storage::disk($import->disk)->exists($path)) {
            return ['rows' => [], 'summary' => []];
        }

        return json_decode(Storage::disk($import->disk)->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function directory(DataImport $import): string
    {
        return trim((string) config('imports.path', 'imports'), '/')."/{$import->company_id}/{$import->uuid}";
    }

    private function sheetIndex(DataImport $import): int
    {
        return max(0, (int) (($import->context ?? [])['sheet_index'] ?? 0));
    }

    private function importContext(DataImport $import): ImportContext
    {
        $meta = array_merge($import->context ?? [], [
            'import_id' => $import->id,
            'sheet_name' => ($import->context ?? [])['sheet_name'] ?? null,
        ]);

        return new ImportContext($import->company, $import->uploader, $import->project, $meta);
    }
}
