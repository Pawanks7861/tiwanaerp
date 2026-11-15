<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\CostCalculationBasis;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\CostRateSource;
use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\ResourceType;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Imports\DataImport;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Planning\PlanningService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Multi-sheet Advanced BOQ workbook import (Jaipur Feed Plant style).
 * Preview/validate first; confirm runs in one DB transaction via existing domain services.
 */
class AdvancedBoqWorkbookImportService
{
    public const MODULE = 'boq.advanced_workbook';

    public function __construct(
        private readonly AdvancedBoqWorkbookImportDetector $detector,
        private readonly AdvancedBoqWorkbookParser $parser,
        private readonly BoqService $boqs,
        private readonly MeasurementSheetService $measurements,
        private readonly CostBuildUpService $costBuildUp,
        private readonly RateAnalysisService $rateAnalyses,
        private readonly BbsService $bbs,
        private readonly RateComparisonService $rateComparison,
        private readonly BoqRevisionService $revisions,
        private readonly PlanningService $planning,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @return array{import: DataImport, detection: array<string, mixed>}
     */
    public function upload(Project $project, User $user, UploadedFile $file, ?Boq $boq = null, string $mode = 'new'): array
    {
        abort_unless(BoqCompanySettings::enabled($project->company), 403);

        if ($file->getClientOriginalExtension() !== 'xlsx'
            && $file->getMimeType() !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            throw ValidationException::withMessages(['file' => 'Upload an .xlsx Advanced Costing workbook.']);
        }

        $sha = hash_file('sha256', $file->getRealPath());
        $duplicate = DataImport::query()
            ->where('project_id', $project->id)
            ->where('module', self::MODULE)
            ->where('status', ImportStatus::Completed)
            ->where('context->file_sha256', $sha)
            ->when($boq, fn ($q) => $q->where('context->boq_id', $boq->id))
            ->latest('id')
            ->first();

        $path = $file->store('imports/boq/advanced-workbook/'.$project->id, 'local');
        $absolute = Storage::disk('local')->path($path);
        $detection = $this->detector->inspectFile($absolute);

        $import = new DataImport;
        $import->forceFill([
            'uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'module' => self::MODULE,
            'mode' => ImportMode::Create->value,
            'status' => ImportStatus::Uploaded,
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'disk' => 'local',
            'path' => $path,
            'uploaded_by' => $user->id,
            'context' => [
                'file_sha256' => $sha,
                'boq_id' => $boq?->id,
                'import_mode' => $mode,
                'duplicate_of' => $duplicate?->id,
                'import_schedule' => false,
                'replace_previous' => false,
            ],
            'summary' => [
                'detection' => $detection,
            ],
        ])->save();

        $warnings = $detection['warnings'];
        if ($duplicate) {
            $warnings[] = 'Same workbook SHA-256 was already imported (batch #'.$duplicate->id.'). Re-import will be blocked unless Replace Previous Import is confirmed.';
        }

        return [
            'import' => $import->fresh(),
            'detection' => [...$detection, 'warnings' => $warnings, 'duplicate_import_id' => $duplicate?->id],
        ];
    }

    /**
     * @param  array{import_schedule?: bool, replace_previous?: bool, material_map?: array<string, int|null>}  $options
     * @return array<string, mixed>
     */
    public function preview(DataImport $import, array $options = []): array
    {
        $this->assertModule($import);
        $absolute = Storage::disk($import->disk)->path($import->path);
        $parsed = $this->parser->parseWorkbook($absolute);

        $materials = Material::query()->active()->get(['id', 'code', 'name']);
        $materialIndex = [];
        foreach ($materials as $m) {
            $materialIndex[mb_strtolower(trim($m->name))] = $m;
            $materialIndex[mb_strtolower(trim($m->code))] = $m;
        }

        $units = Unit::query()->get(['id', 'symbol', 'name']);
        $unitIndex = [];
        foreach ($units as $u) {
            $unitIndex[mb_strtolower(trim((string) $u->symbol))] = $u;
            $unitIndex[mb_strtolower(trim((string) $u->name))] = $u;
        }

        $itemCount = 0;
        $materialComponents = 0;
        $labourComponents = 0;
        $mappingWarnings = [];
        $previewItems = [];

        foreach ($parsed['costing_sections'] as $section) {
            foreach ($section['items'] as $item) {
                $itemCount++;
                $unit = $unitIndex[mb_strtolower($item['unit'])] ?? null;
                if ($unit === null) {
                    $mappingWarnings[] = "Unknown unit [{$item['unit']}] for {$item['name']} (row {$item['source_row']})";
                }
                $mats = [];
                foreach ($item['materials'] as $mat) {
                    $materialComponents++;
                    $key = mb_strtolower($mat['name']);
                    $mapped = $options['material_map'][$key] ?? null;
                    $master = $mapped ? $materials->firstWhere('id', (int) $mapped) : ($materialIndex[$key] ?? null);
                    if ($master === null) {
                        $mappingWarnings[] = "Missing Material Master for [{$mat['name']}] — will import as description-only component.";
                    }
                    $mats[] = [...$mat, 'material_id' => $master?->id, 'material_code' => $master?->code];
                }
                if ($item['labour_rate'] !== null) {
                    $labourComponents++;
                }
                $previewItems[] = [
                    ...$item,
                    'unit_id' => $unit?->id,
                    'materials' => $mats,
                ];
            }
        }

        $bbsLines = collect($parsed['bbs'])->sum(fn ($b) => count($b['lines']));
        $warnings = array_values(array_unique([
            ...$parsed['warnings'],
            ...$mappingWarnings,
            ...collect($parsed['costing_sections'])->flatMap(fn ($s) => $s['warnings'])->all(),
            ...collect($parsed['bbs'])->flatMap(fn ($s) => $s['warnings'])->all(),
        ]));

        $context = $import->context ?? [];
        $context['import_schedule'] = (bool) ($options['import_schedule'] ?? $context['import_schedule'] ?? false);
        $context['replace_previous'] = (bool) ($options['replace_previous'] ?? $context['replace_previous'] ?? false);
        $context['material_map'] = $options['material_map'] ?? ($context['material_map'] ?? []);

        $import->forceFill([
            'status' => ImportStatus::Ready,
            'context' => $context,
            'total_rows' => $itemCount,
            'valid_rows' => $itemCount,
            'summary' => [
                'detection' => $parsed['detection'],
                'preview' => [
                    'sections' => count($parsed['costing_sections']),
                    'items' => $itemCount,
                    'material_components' => $materialComponents,
                    'labour_components' => $labourComponents,
                    'rate_analyses' => count($parsed['rate_analyses']),
                    'bbs_lines' => $bbsLines,
                    'rate_comparison_quotes' => count($parsed['rate_comparison']),
                    'schedule_activities' => count($parsed['schedule']['activities'] ?? []),
                ],
                'parsed' => $parsed,
                'preview_items' => array_slice($previewItems, 0, 200),
                'warnings' => $warnings,
                'errors' => $parsed['errors'],
            ],
        ])->save();

        return [
            'import' => $import->fresh(),
            'preview' => $import->summary['preview'],
            'preview_items' => $import->summary['preview_items'],
            'warnings' => $warnings,
            'errors' => $parsed['errors'],
            'reconcile' => $parsed['reconcile'],
            'detection' => $parsed['detection'],
            'rate_analyses' => $parsed['rate_analyses'],
            'bbs' => collect($parsed['bbs'])->map(fn ($b) => [
                'sheet' => $b['sheet'],
                'lines' => count($b['lines']),
                'wastage_percent' => $b['wastage_percent'],
            ])->all(),
            'rate_comparison' => $parsed['rate_comparison'],
            'schedule' => $parsed['schedule'],
            'duplicate_import_id' => $context['duplicate_of'] ?? null,
        ];
    }

    /**
     * @param  array{import_schedule?: bool, replace_previous?: bool, create_missing_materials?: bool}  $options
     * @return array<string, mixed>
     */
    public function confirm(DataImport $import, Project $project, User $user, array $options = []): array
    {
        $this->assertModule($import);
        abort_unless(BoqCompanySettings::enabled($project->company), 403);

        if ($import->status !== ImportStatus::Ready && $import->status !== ImportStatus::Uploaded) {
            $this->preview($import, $options);
            $import->refresh();
        }

        $summary = $import->summary ?? [];
        $errors = $summary['errors'] ?? [];
        if ($errors !== []) {
            throw ValidationException::withMessages(['import' => 'Resolve parse errors before confirming.']);
        }

        $context = $import->context ?? [];
        $replace = (bool) ($options['replace_previous'] ?? $context['replace_previous'] ?? false);
        if (! empty($context['duplicate_of']) && ! $replace) {
            throw ValidationException::withMessages([
                'import' => 'This workbook was already imported. Enable Replace Previous Import to continue.',
            ]);
        }

        $parsed = $summary['parsed'] ?? null;
        if ($parsed === null) {
            $preview = $this->preview($import, $options);
            $parsed = $import->fresh()->summary['parsed'];
        }

        $importSchedule = (bool) ($options['import_schedule'] ?? $context['import_schedule'] ?? false);

        $import->forceFill([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ])->save();

        try {
            $result = DB::transaction(function () use ($import, $project, $user, $parsed, $context, $replace, $importSchedule) {
                $boq = $this->resolveTargetBoq($project, $user, $context);

                if ($replace && ! empty($context['duplicate_of'])) {
                    app(BoqImportService::class)->replaceImportBatch($boq, (int) $context['duplicate_of']);
                }

                $created = $this->importCosting($boq, $user, $parsed['costing_sections'] ?? [], $import->id, $context['material_map'] ?? []);
                $rateAnalysisIds = $this->importRateAnalyses($project, $parsed['rate_analyses'] ?? []);
                $bbsIds = $this->importBbs($boq, $user, $parsed['bbs'] ?? [], $created['steel_item_ids']);
                $quoteCount = $this->importRateComparison($boq, $user, $parsed['rate_comparison'] ?? [], $created['first_item_id']);

                $planningCount = 0;
                if ($importSchedule && ! empty($parsed['schedule']['activities'])) {
                    $planningCount = $this->importScheduleOptional($project, $user, $parsed['schedule']['activities']);
                }

                $this->boqs->recalculateTotals($boq->fresh());
                $boq->forceFill(['source_import_id' => $import->id])->save();

                $boq->writeAudit('advanced_workbook_imported', null, [
                    'import_id' => $import->id,
                    'sections' => $created['sections'],
                    'items' => $created['items'],
                    'measurements' => $created['measurements'],
                    'material_components' => $created['materials'],
                    'labour_components' => $created['labours'],
                    'rate_analyses' => count($rateAnalysisIds),
                    'bbs_headers' => count($bbsIds),
                    'rate_comparison_quotes' => $quoteCount,
                    'planning_activities' => $planningCount,
                ]);

                return [
                    'boq_id' => $boq->id,
                    'boq_number' => $boq->boq_number,
                    'boq_version' => $boq->version,
                    'sections_created' => $created['sections'],
                    'items_created' => $created['items'],
                    'measurements_imported' => $created['measurements'],
                    'material_components' => $created['materials'],
                    'labour_components' => $created['labours'],
                    'rate_analyses' => count($rateAnalysisIds),
                    'bbs_headers' => count($bbsIds),
                    'rate_comparison_quotes' => $quoteCount,
                    'planning_activities' => $planningCount,
                    'ignored_sheets' => ['Job Respons'],
                    'budget_sync' => 'Budget Sync Required',
                ];
            });
        } catch (\Throwable $e) {
            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
            throw $e;
        }

        $report = $this->buildImportReport($parsed, $result);
        $reportPath = 'imports/boq/advanced-workbook/reports/'.$import->uuid.'.json';
        Storage::disk('local')->put($reportPath, json_encode($report, JSON_PRETTY_PRINT));

        $import->forceFill([
            'status' => ImportStatus::Completed,
            'finished_at' => now(),
            'create_count' => (int) ($result['items_created'] ?? 0),
            'summary' => array_merge($import->summary ?? [], [
                'result' => $result,
                'reconciliation' => $this->buildReconciliation($parsed),
                'report_path' => $reportPath,
            ]),
            'context' => array_merge($import->context ?? [], ['boq_id' => $result['boq_id']]),
        ])->save();

        return [
            'import' => $import->fresh(),
            'result' => $result,
            'reconciliation' => $import->summary['reconciliation'] ?? [],
            'side_effects' => [
                'budget_auto_sync' => false,
                'mr' => 0,
                'po' => 0,
                'grn' => 0,
                'stock' => 0,
                'finance' => 0,
                'dpr' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(DataImport $import): array
    {
        $this->assertModule($import);
        $path = $import->summary['report_path'] ?? null;
        if ($path && Storage::disk('local')->exists($path)) {
            return json_decode(Storage::disk('local')->get($path), true) ?? [];
        }

        return [
            'import_id' => $import->id,
            'status' => $import->status->value,
            'result' => $import->summary['result'] ?? null,
            'reconciliation' => $import->summary['reconciliation'] ?? null,
            'warnings' => $import->summary['warnings'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveTargetBoq(Project $project, User $user, array $context): Boq
    {
        $mode = (string) ($context['import_mode'] ?? 'new');
        $boqId = isset($context['boq_id']) ? (int) $context['boq_id'] : null;

        if ($mode === 'new' || $boqId === null) {
            // Workbook import may create a Draft without drawing links (linked later on BOQ).
            $boq = new Boq(['title' => 'Feed Plant Costing Import']);
            $boq->forceFill([
                'project_id' => $project->id,
                'boq_number' => $this->numbers->next('boq', $project),
                'version' => 1,
                'status' => BoqStatus::Draft,
                'is_current' => false,
                'total_cost_amount' => '0',
                'total_client_amount' => '0',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            return $boq;
        }

        $boq = Boq::query()->where('project_id', $project->id)->whereKey($boqId)->firstOrFail();

        if ($mode === 'revision' || ! $boq->isEditable()) {
            if ($boq->status === BoqStatus::Approved) {
                return $this->revisions->revise($boq, 'Advanced workbook import', $user);
            }
            throw ValidationException::withMessages([
                'boq' => 'Approved BOQ cannot be mutated. Use Import Into New Revision.',
            ]);
        }

        $boq->assertEditable();

        return $boq;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, int>  $materialMap
     * @return array{sections: int, items: int, measurements: int, materials: int, labours: int, first_item_id: ?int, steel_item_ids: list<int>}
     */
    private function importCosting(Boq $boq, User $user, array $sections, int $importId, array $materialMap): array
    {
        $sectionCount = 0;
        $itemCount = 0;
        $measurementCount = 0;
        $materialCount = 0;
        $labourCount = 0;
        $firstItemId = null;
        $steelItemIds = [];
        $sort = (int) $boq->items()->max('sort_order');
        $units = Unit::query()->get();
        $unitIndex = [];
        foreach ($units as $u) {
            $unitIndex[mb_strtolower(trim((string) $u->symbol))] = $u;
            $unitIndex[mb_strtolower(trim((string) $u->name))] = $u;
        }
        $unitAliases = [
            'rmt' => ['rmt', 'rm', 'm', 'meter', 'metre'],
            'sqm' => ['sqm', 'sq.m', 'm2'],
            'sft' => ['sft', 'sqft', 'sq.ft'],
            'cft' => ['cft', 'cu.ft'],
            'cum' => ['cum', 'm3'],
        ];
        $fallbackUnit = $unitIndex['cum'] ?? $unitIndex['nos'] ?? $units->first();
        $materials = Material::query()->active()->get();
        $byName = $materials->keyBy(fn ($m) => mb_strtolower(trim($m->name)));

        foreach ($sections as $block) {
            $root = $this->boqs->saveSection($boq, [
                'code' => Str::upper(Str::substr(Str::slug($block['root_section'], '_'), 0, 12)) ?: 'IMP',
                'name' => $block['root_section'],
            ]);
            $sectionCount++;
            $subCache = [];

            foreach ($block['items'] as $row) {
                $section = $root;
                if (! empty($row['subsection'])) {
                    $key = mb_strtolower($row['subsection']);
                    if (! isset($subCache[$key])) {
                        $subCache[$key] = $this->boqs->saveSection($boq, [
                            'code' => Str::upper(Str::substr(Str::slug($row['subsection'], '_'), 0, 12)) ?: 'SUB',
                            'name' => $row['subsection'],
                            'parent_id' => $root->id,
                        ]);
                        $sectionCount++;
                    }
                    $section = $subCache[$key];
                }

                $unitKey = mb_strtolower((string) $row['unit']);
                $unit = $unitIndex[$unitKey] ?? null;
                if ($unit === null) {
                    foreach ($unitAliases as $canonical => $aliases) {
                        if (in_array($unitKey, $aliases, true) && isset($unitIndex[$canonical])) {
                            $unit = $unitIndex[$canonical];
                            break;
                        }
                    }
                }
                $unit ??= $fallbackUnit;
                $item = new BoqItem([
                    'line_uid' => (string) Str::uuid(),
                    'boq_section_id' => $section->id,
                    'item_code' => null,
                    'name' => mb_substr((string) $row['name'], 0, 200),
                    'unit_id' => $unit?->id,
                    'quantity' => $row['erp_qty'] ?? '0',
                    'client_rate' => '0',
                    'sort_order' => ++$sort,
                    'quantity_source' => QuantitySource::Measurement,
                ]);
                $item->forceFill([
                    'source_type' => 'advanced_workbook',
                    'source_import_id' => $importId,
                    'source_sheet' => $row['source_sheet'],
                    'source_row' => $row['source_row'],
                ]);
                $item->boq()->associate($boq);
                $item->save();
                $itemCount++;
                $firstItemId ??= $item->id;

                if (str_contains(mb_strtolower($item->name), 'steel') && ! str_contains(mb_strtolower($item->name), 'shutter')) {
                    $steelItemIds[] = $item->id;
                }

                $sheet = $this->measurements->ensureSheet($item, $user);
                $this->measurements->saveLines($sheet, [[
                    'description' => $row['name'],
                    'formula_type' => $row['formula_type'],
                    'nos' => $row['nos'] ?? '1',
                    'length' => $row['length'],
                    'width' => $row['width'],
                    'height_depth' => $row['height_depth'],
                    'manual_quantity' => $row['formula_type'] === 'manual' ? ($row['erp_qty'] ?? $row['excel_qty']) : null,
                    'unit_id' => $unit?->id,
                    'sort_order' => 0,
                ]], $user);
                $this->measurements->setQuantitySource($item->fresh(), QuantitySource::Measurement, null, $user);
                $measurementCount++;

                $components = [];
                $seq = 1;
                foreach ($row['materials'] as $mat) {
                    $key = mb_strtolower($mat['name']);
                    $materialId = $materialMap[$key] ?? $byName->get($key)?->id;
                    $components[] = [
                        'component_type' => CostComponentType::Material->value,
                        'description' => $mat['name'],
                        'calculation_basis' => CostCalculationBasis::QuantityRate->value,
                        'consumption_qty' => $mat['consumption_qty'],
                        'consumption_unit' => $mat['consumption_unit'],
                        'rate' => $mat['rate'],
                        'rate_source' => CostRateSource::Manual->value,
                        'source_id' => $materialId,
                        'wastage_percent' => '0',
                        'is_active' => true,
                        'sequence' => $seq++,
                    ];
                    $materialCount++;
                }
                if ($row['labour_rate'] !== null && Decimal::of($row['labour_rate'])->isPositive()) {
                    $components[] = [
                        'component_type' => CostComponentType::Labour->value,
                        'description' => $row['subsection'] ? $row['subsection'].' Labour' : 'Labour',
                        'calculation_basis' => CostCalculationBasis::QuantityRate->value,
                        'consumption_qty' => '1',
                        'consumption_unit' => $row['unit'],
                        'rate' => $row['labour_rate'],
                        'rate_source' => CostRateSource::Manual->value,
                        'wastage_percent' => '0',
                        'is_active' => true,
                        'sequence' => $seq++,
                    ];
                    $labourCount++;
                }
                if ($components !== []) {
                    $this->costBuildUp->saveComponents($item->fresh(), $components, $user);
                }
            }
        }

        return [
            'sections' => $sectionCount,
            'items' => $itemCount,
            'measurements' => $measurementCount,
            'materials' => $materialCount,
            'labours' => $labourCount,
            'first_item_id' => $firstItemId,
            'steel_item_ids' => $steelItemIds,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return list<int>
     */
    private function importRateAnalyses(Project $project, array $profiles): array
    {
        $ids = [];
        $cum = Unit::query()->where('symbol', 'Cum')->first();
        if ($cum === null) {
            return [];
        }

        foreach ($profiles as $profile) {
            $items = [];
            foreach ($profile['items'] as $line) {
                $items[] = [
                    'resource_type' => $line['resource_type'] === 'labour'
                        ? ResourceType::Labour->value
                        : ResourceType::Material->value,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'wastage_percent' => '0',
                ];
            }
            if ($items === []) {
                continue;
            }
            $analysis = $this->rateAnalyses->save($project, [
                'name' => $profile['name'],
                'description' => 'Imported from '.$profile['source_sheet'],
                'unit_id' => $cum->id,
                'output_quantity' => '1',
                'overhead_percent' => $profile['water_percent'] ?? '0',
                'profit_percent' => '0',
                'items' => $items,
            ]);
            $ids[] = $analysis->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $bbsBlocks
     * @param  list<int>  $steelItemIds
     * @return list<int>
     */
    private function importBbs(Boq $boq, User $user, array $bbsBlocks, array $steelItemIds): array
    {
        $ids = [];
        $unit = Unit::query()->where('symbol', 'MT')->first()
            ?? Unit::query()->where('symbol', 'Kg')->first();

        foreach ($bbsBlocks as $i => $block) {
            if (($block['lines'] ?? []) === []) {
                continue;
            }
            $itemId = $steelItemIds[$i] ?? $steelItemIds[0] ?? null;
            if ($itemId === null) {
                $section = $this->boqs->saveSection($boq, [
                    'code' => 'STL',
                    'name' => 'Steel',
                ]);
                $item = new BoqItem([
                    'line_uid' => (string) Str::uuid(),
                    'boq_section_id' => $section->id,
                    'name' => 'Reinforcement — '.$block['sheet'],
                    'unit_id' => $unit?->id,
                    'quantity' => '1',
                    'client_rate' => '0',
                    'sort_order' => 9000 + $i,
                ]);
                $item->boq()->associate($boq);
                $item->save();
                $itemId = $item->id;
            }

            $item = BoqItem::query()->findOrFail($itemId);
            $bbs = $this->bbs->enable($item, $user);
            $lines = [];
            foreach ($block['lines'] as $line) {
                $lines[] = [
                    'structure_location' => mb_substr((string) $line['structure_location'], 0, 120),
                    'bar_mark' => $line['bar_mark'],
                    'shape_code' => 'custom_whitelisted',
                    'diameter_mm' => $line['diameter_mm'],
                    'number_of_members' => $line['number_of_members'],
                    'bars_per_member' => $line['bars_per_member'],
                    'a_length' => $line['a_length'],
                    'b_length' => $line['b_length'],
                    'c_length' => $line['c_length'],
                    'd_length' => $line['d_length'],
                    'hook_length' => $line['hook_length'],
                    'bend_deduction' => $line['bend_deduction'],
                ];
            }
            $bbs = $this->bbs->save($bbs, [
                'title' => $block['sheet'],
                'wastage_percent' => $block['wastage_percent'] ?? '0',
            ], $lines, [], $user);
            $ids[] = $bbs->id;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $quotes
     */
    private function importRateComparison(Boq $boq, User $user, array $quotes, ?int $fallbackItemId): int
    {
        if ($quotes === [] || $fallbackItemId === null) {
            return 0;
        }

        $item = BoqItem::query()->where('boq_id', $boq->id)->whereKey($fallbackItemId)->first()
            ?? BoqItem::query()->where('boq_id', $boq->id)->orderBy('id')->first();
        if ($item === null) {
            return 0;
        }

        $rows = [];
        foreach ($quotes as $q) {
            $rows[] = [
                'vendor_name' => $q['vendor_name'],
                'quoted_rate' => $q['quoted_rate'],
                'gst_mode' => 'inclusive',
                'remarks' => $q['description'],
            ];
        }
        $this->rateComparison->saveQuotes($item, $rows, $user);

        return count($rows);
    }

    /**
     * Optional planning import — disabled by default. Planning remains BOQ-independent.
     *
     * @param  list<array<string, mixed>>  $activities
     */
    private function importScheduleOptional(Project $project, User $user, array $activities): int
    {
        $head = $this->planning->saveTask($project, [
            'name' => 'Workbook Schedule Import',
            'as_head' => true,
            'description' => 'Imported from Advanced BOQ costing workbook Schedule sheet.',
        ]);

        $unit = Unit::query()->where('symbol', 'Nos')->first()
            ?? Unit::query()->orderBy('id')->first();
        $start = now()->toDateString();
        $finish = now()->addDays(30)->toDateString();
        $count = 0;

        foreach (array_slice($activities, 0, 100) as $i => $activity) {
            if (($activity['name'] ?? '') === '') {
                continue;
            }
            $mappedUnit = $unit;
            if (! empty($activity['unit'])) {
                $mappedUnit = Unit::query()->where('symbol', $activity['unit'])->first() ?? $unit;
            }
            $this->planning->saveTask($project, [
                'parent_id' => $head->id,
                'name' => mb_substr((string) $activity['name'], 0, 200),
                'planned_start' => $start,
                'planned_finish' => $finish,
                'unit_id' => $mappedUnit?->id,
                'planned_qty' => '1',
                'sort_order' => $i + 1,
                'description' => 'Source row '.($activity['source_row'] ?? ''),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildImportReport(array $parsed, array $result): array
    {
        $rows = [];
        foreach ($parsed['costing_sections'] ?? [] as $section) {
            foreach ($section['items'] as $item) {
                $rows[] = [
                    'sheet' => $item['source_sheet'],
                    'source_row' => $item['source_row'],
                    'imported_entity' => 'boq_item',
                    'status' => 'imported',
                    'source_value' => $item['excel_qty'],
                    'erp_recalculated_value' => $item['erp_qty'],
                    'difference' => $item['excel_qty'] !== null && $item['erp_qty'] !== null
                        ? (string) Decimal::of($item['erp_qty'])->minus($item['excel_qty'])
                        : null,
                    'warning' => $item['warning'],
                ];
            }
        }
        foreach ($parsed['warnings'] ?? [] as $warning) {
            $rows[] = [
                'sheet' => null,
                'source_row' => null,
                'imported_entity' => 'warning',
                'status' => str_contains($warning, 'BROKEN_EXCEL_REFERENCE') ? 'warning' : 'warning',
                'source_value' => null,
                'erp_recalculated_value' => null,
                'difference' => null,
                'warning' => $warning,
            ];
        }

        return [
            'result' => $result,
            'rows' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function buildReconciliation(array $parsed): array
    {
        return [
            'steel_summary' => $parsed['reconcile']['steel_summary'] ?? [],
            'material_consumption' => $parsed['reconcile']['material_consumption'] ?? [],
            'labour_analysis' => $parsed['reconcile']['labour_analysis'] ?? [],
            'notes' => [
                'Steel Summary / Material Consumption / Estimated labour are reconcile-only.',
                'Broken Excel #REF! cells produce warnings and are ignored.',
                'ERP recalculation is authoritative for measurements, BBS and costing.',
            ],
        ];
    }

    private function assertModule(DataImport $import): void
    {
        if ($import->module !== self::MODULE) {
            throw ValidationException::withMessages(['import' => 'Not an Advanced BOQ workbook import.']);
        }
    }
}
