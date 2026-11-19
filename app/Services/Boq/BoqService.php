<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\QuantitySource;
use App\Enums\Boq\RateAnalysisStatus;
use App\Enums\Boq\SimpleBoqMeasurementFormula;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\ConcreteMixProfile;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Projects\ProjectWbsService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BOQ lifecycle and line maintenance. Every write re-checks the BOQ state, because policies alone
 * are bypassed for platform super admins.
 */
class BoqService
{
    /** Descriptive line columns any BOQ editor may change. */
    private const LINE_FIELDS = [
        'item_code', 'name', 'description', 'hsn_sac', 'unit_id', 'gst_rate',
        'project_cost_head_id',
        'source_type', 'source_import_id', 'source_sheet', 'source_row',
    ];

    /** Simplified BOQ measurement columns (ignored as qty authority for Advanced BOQ). */
    private const MEASUREMENT_FIELDS = [
        'measurement_mode', 'measurement_formula', 'measurement_nob',
        'measurement_nos', 'measurement_length', 'measurement_width', 'measurement_height',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{title: string, drawing_revision_ids?: list<int|string>}  $data
     */
    public function create(Project $project, array $data): Boq
    {
        return DB::transaction(function () use ($project, $data) {
            $revisionIds = $this->assertEligibleRevisions($project, $data['drawing_revision_ids'] ?? []);

            $boq = new Boq(['title' => $data['title']]);
            $boq->forceFill([
                'project_id' => $project->id,
                'boq_number' => $this->numbers->next('boq', $project),
                'version' => 1,
                'status' => BoqStatus::Draft,
                'is_current' => false,
            ])->save();

            $boq->drawingRevisions()->sync($revisionIds);
            $boq->writeAudit('drawings_linked', null, ['drawing_revision_ids' => $revisionIds]);

            return $boq;
        });
    }

    /**
     * Replace the source drawings of an editable BOQ. Already-linked superseded revisions may be
     * kept so a later approval never silently rewrites the original source; new picks must be the
     * currently approved revision of a drawing on this project.
     *
     * @param  list<int|string>  $ids
     */
    public function syncDrawingRevisions(Boq $boq, array $ids): Boq
    {
        $boq->assertEditable();

        return DB::transaction(function () use ($boq, $ids) {
            $locked = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->load('project');

            $previous = $locked->drawingRevisions()->pluck('drawing_revisions.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $revisionIds = $this->assertEligibleRevisions($locked->project, $ids, $previous);
            $locked->drawingRevisions()->sync($revisionIds);

            $next = collect($revisionIds)->sort()->values()->all();
            if ($previous !== $next) {
                $locked->writeAudit('drawings_linked', ['drawing_revision_ids' => $previous], ['drawing_revision_ids' => $next]);
            }

            return $locked;
        });
    }

    /**
     * Currently approved drawing revisions of this project (the drawing's current_revision_id).
     *
     * @return Collection<int, DrawingRevision>
     */
    public function eligibleDrawingRevisions(Project $project): Collection
    {
        return DrawingRevision::query()
            ->where('status', DrawingRevisionStatus::Approved)
            ->whereHas('drawing', fn (Builder $q) => $q
                ->where('project_id', $project->id)
                ->whereColumn('current_revision_id', 'drawing_revisions.id'))
            ->with([
                'drawing:id,company_id,project_id,drawing_number,title,current_revision_id,status',
                'drawing.currentRevision.decider:id,name',
                'decider:id,name',
            ])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (DrawingRevision $revision) => [
                $revision->drawing?->drawing_number,
                $revision->revision_code,
            ])
            ->values();
    }

    /**
     * A later revision of a linked drawing was approved. The BOQ keeps its original source;
     * this only records that a review may be needed.
     */
    public function recordNewerApprovedRevision(DrawingRevision $previous, DrawingRevision $current): void
    {
        Boq::query()
            ->whereHas('drawingRevisions', fn (Builder $q) => $q->whereKey($previous->id))
            ->orderBy('id')
            ->each(function (Boq $boq) use ($previous, $current) {
                $boq->writeAudit('newer_drawing_revision_available', [
                    'drawing_revision_id' => $previous->id,
                    'revision_code' => $previous->revision_code,
                ], [
                    'drawing_revision_id' => $current->id,
                    'revision_code' => $current->revision_code,
                ]);
            });
    }

    /**
     * @param  array{title: string}  $data
     */
    public function update(Boq $boq, array $data): Boq
    {
        $boq->assertEditable();
        $boq->fill($data)->save();

        return $boq;
    }

    public function delete(Boq $boq): void
    {
        $boq->assertEditable();
        $boq->delete();
    }

    /**
     * @param  array{parent_id?: int|null, code?: string|null, name: string, discipline?: string|null, sort_order?: int|null}  $data
     */
    public function saveSection(Boq $boq, array $data, ?BoqSection $section = null): BoqSection
    {
        $boq->assertEditable();

        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = $boq->sections()->find($parentId);
            if ($parent === null || $parent->parent_id !== null || ($section && $parent->id === $section->id)) {
                throw ValidationException::withMessages(['parent_id' => 'Choose a top-level section of this BOQ (two levels at most).']);
            }
            if ($section && $section->children()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'A section that has subsections cannot itself become a subsection.']);
            }
        }

        $section ??= new BoqSection;
        $section->boq()->associate($boq);
        $fill = [
            'parent_id' => $parentId,
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'discipline' => $data['discipline'] ?? null,
            'sort_order' => $data['sort_order'] ?? ($section->exists
                ? $section->sort_order
                : (int) $boq->sections()->where('parent_id', $parentId)->max('sort_order') + 1),
        ];
        if (array_key_exists('default_project_cost_head_id', $data)) {
            $rawDefault = $data['default_project_cost_head_id'];
            $fill['default_project_cost_head_id'] = ($rawDefault === null || $rawDefault === '')
                ? null
                : (int) $rawDefault;
        }
        $section->fill($fill)->save();

        return $section;
    }

    public function deleteSection(Boq $boq, BoqSection $section): void
    {
        $boq->assertEditable();

        if ($section->children()->exists() || $section->items()->exists()) {
            throw ValidationException::withMessages(['section' => 'Only empty sections can be deleted. Move or delete its items and subsections first.']);
        }

        $section->boq()->associate($boq);
        $section->delete();
    }

    /**
     * Save the editor grid in one transaction: create/update the given rows and delete the listed ids.
     * Users without boq.view_costs cannot change cost inputs; existing values are preserved for them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $deletedIds
     */
    public function syncItems(Boq $boq, array $rows, array $deletedIds, bool $canEditCosts): Boq
    {
        return DB::transaction(function () use ($boq, $rows, $deletedIds, $canEditCosts) {
            Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail()->assertEditable();

            /** @var Collection<int, BoqItem> $existing */
            $existing = $boq->items()->get()->keyBy('id');
            $sectionIds = $boq->sections()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $analyses = $canEditCosts ? $this->applicableAnalyses($boq, collect($rows)->pluck('rate_analysis_id')->filter()->all()) : collect();

            foreach ($deletedIds as $i => $id) {
                $item = $existing->get($id) ?? throw ValidationException::withMessages(["deleted_ids.{$i}" => 'This line does not belong to the BOQ.']);
                $item->setRelation('boq', $boq)->delete();
                $existing->forget($id);
            }

            foreach (array_values($rows) as $i => $row) {
                $item = isset($row['id'])
                    ? ($existing->get((int) $row['id']) ?? throw ValidationException::withMessages(["rows.{$i}.id" => 'This line does not belong to the BOQ.']))
                    : new BoqItem(['line_uid' => (string) Str::uuid()]);

                if (! in_array((int) $row['boq_section_id'], $sectionIds, true)) {
                    throw ValidationException::withMessages(["rows.{$i}.boq_section_id" => 'Choose a section of this BOQ.']);
                }

                $item->boq()->associate($boq);
                $item->boq_section_id = (int) $row['boq_section_id'];
                $this->fillLine($item, $row, $canEditCosts, $analyses, "rows.{$i}");
                $item->sort_order = (int) ($row['sort_order'] ?? $i);
                $item->save();

                if ($canEditCosts) {
                    $materials = app(SimpleBoqMaterialCostService::class);
                    if (array_key_exists('simple_materials', $row) && is_array($row['simple_materials'])) {
                        $materials->syncForItem(
                            $boq,
                            $item,
                            $row['simple_materials'],
                            auth()->user(),
                            "rows.{$i}.simple_materials",
                        );
                        $this->applySimplifiedMaterialRate($boq, $item, $materials);
                    } elseif (
                        ! BoqCompanySettings::enabled($boq->company)
                        && ! $item->simple_materials_initialized
                        && $materials->isPccWork($item)
                    ) {
                        // Save-without-edit: persist PCC defaults shown in the drawer.
                        $materials->syncForItem(
                            $boq,
                            $item,
                            $materials->defaultRowsFor($item),
                            auth()->user(),
                            "rows.{$i}.simple_materials",
                        );
                        $this->applySimplifiedMaterialRate($boq, $item, $materials);
                    }
                }
            }

            $boq = $this->recalculateTotals($boq);
            app(BoqBudgetMaterialSyncService::class)->syncAfterBoqMaterialsChanged($boq);

            return $boq;
        });
    }

    /**
     * Fill descriptive fields and server-calculated amounts on a line (not saved).
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, RateAnalysis>  $analyses
     */
    public function fillLine(BoqItem $item, array $row, bool $canEditCosts, Collection $analyses, string $errorKey = 'row'): void
    {
        $item->fill(array_intersect_key($row, array_flip(self::LINE_FIELDS)));

        $costs = $canEditCosts ? $row : [];
        foreach ([...BoqCalculator::RATE_FIELDS, 'margin_percent'] as $field) {
            if (! $canEditCosts || ! array_key_exists($field, $row)) {
                $costs[$field] = $item->exists ? $item->getAttribute($field) : '0';
            }
        }

        $analysisId = $canEditCosts && array_key_exists('rate_analysis_id', $row)
            ? ($row['rate_analysis_id'] !== null ? (int) $row['rate_analysis_id'] : null)
            : $item->rate_analysis_id;

        if ($analysisId !== null && $analysisId !== $item->rate_analysis_id) {
            $analysis = $analyses->get($analysisId)
                ?? throw ValidationException::withMessages(["{$errorKey}.rate_analysis_id" => 'Choose an approved rate analysis of this project or the company library.']);
            $costs = [...$costs, ...$this->snapshotFromAnalysis($analysis)];
            $row['client_rate'] = $costs['client_rate'];
        }

        $source = $item->quantity_source instanceof QuantitySource
            ? $item->quantity_source
            : QuantitySource::tryFrom((string) ($row['quantity_source'] ?? $item->getAttribute('quantity_source') ?? 'direct'))
                ?? QuantitySource::Direct;

        if (array_key_exists('quantity_source', $row) && $canEditCosts) {
            // quantity_source is managed via item workspace / measurement service; ignore silent client changes here
        }

        $quantity = (string) ($row['quantity'] ?? '0');
        if ($source === QuantitySource::Measurement && $item->exists) {
            // Authoritative qty comes from approved measurement sheet — do not trust grid quantity.
            $quantity = (string) $item->quantity;
        }

        // Simplified BOQ: server-authoritative Nos/L/W/H → Qty. Advanced BOQ ignores these fields.
        if (! BoqCompanySettings::enabled() && $source !== QuantitySource::Measurement) {
            $unitSymbol = null;
            if (! empty($row['unit_id'])) {
                $unitSymbol = Unit::query()->whereKey((int) $row['unit_id'])->value('symbol');
            } elseif ($item->unit_id) {
                $unitSymbol = $item->relationLoaded('unit')
                    ? $item->unit?->symbol
                    : Unit::query()->whereKey($item->unit_id)->value('symbol');
            }

            $modeRaw = $row['measurement_mode'] ?? null;
            $modeBlank = ! array_key_exists('measurement_mode', $row)
                || $modeRaw === null
                || $modeRaw === '';
            // New Simplified lines only (never rewrite historical null mode on existing rows).
            // Import / API with blank mode + dimensional fields → Auto.
            // Qty-only imports (no dims) keep null mode and use submitted quantity.
            // The BOQ editor always sends measurement_mode=auto for new UI rows.
            if (! $item->exists && $modeBlank) {
                $inferred = SimpleBoqMeasurementFormula::tryFromUnitSymbol(
                    is_string($unitSymbol) ? $unitSymbol : null
                );
                $hasDims = filled($row['measurement_nos'] ?? null)
                    || filled($row['measurement_length'] ?? null)
                    || filled($row['measurement_width'] ?? null)
                    || filled($row['measurement_height'] ?? null);
                if ($inferred !== null && $hasDims) {
                    $row['measurement_mode'] = 'auto';
                    if (empty($row['measurement_formula'] ?? null)) {
                        $row['measurement_formula'] = $inferred->value;
                    }
                    if (! array_key_exists('measurement_nob', $row) || $row['measurement_nob'] === null || $row['measurement_nob'] === '') {
                        $row['measurement_nob'] = 1;
                    }
                }
            }

            $measurementInput = [
                'measurement_mode' => $row['measurement_mode'] ?? $item->getAttribute('measurement_mode')?->value ?? $item->getAttribute('measurement_mode'),
                'measurement_formula' => $row['measurement_formula'] ?? $item->getAttribute('measurement_formula')?->value ?? $item->getAttribute('measurement_formula'),
                'measurement_nob' => array_key_exists('measurement_nob', $row) ? $row['measurement_nob'] : ($item->measurement_nob ?? 1),
                'measurement_nos' => array_key_exists('measurement_nos', $row) ? $row['measurement_nos'] : $item->measurement_nos,
                'measurement_length' => array_key_exists('measurement_length', $row) ? $row['measurement_length'] : $item->measurement_length,
                'measurement_width' => array_key_exists('measurement_width', $row) ? $row['measurement_width'] : $item->measurement_width,
                'measurement_height' => array_key_exists('measurement_height', $row) ? $row['measurement_height'] : $item->measurement_height,
                'quantity' => $quantity,
                'unit_symbol' => $unitSymbol,
                'allow_zero' => true,
            ];

            try {
                $resolved = SimpleBoqMeasurementCalculator::resolve($measurementInput);
            } catch (ValidationException $e) {
                $messages = [];
                foreach ($e->errors() as $field => $errs) {
                    $messages["{$errorKey}.{$field}"] = $errs;
                }
                throw ValidationException::withMessages($messages);
            }

            $quantity = $resolved['quantity'];
            $item->fill([
                'measurement_mode' => $resolved['measurement_mode'],
                'measurement_formula' => $resolved['measurement_formula'],
                'measurement_nob' => (int) $resolved['measurement_nob'],
                'measurement_nos' => $resolved['measurement_nos'],
                'measurement_length' => $resolved['measurement_length'],
                'measurement_width' => $resolved['measurement_width'],
                'measurement_height' => $resolved['measurement_height'],
            ]);

            $dirtyMeas = [];
            foreach (self::MEASUREMENT_FIELDS as $field) {
                if ($item->wasChanged($field) || $item->isDirty($field)) {
                    $dirtyMeas[$field] = $item->getAttribute($field);
                }
            }
            if ($dirtyMeas !== [] && $item->exists) {
                // Audit trail via BOQ writeAudit when parent is available after save (see syncItems caller).
            }
        }

        // Simple/Normal BOQ: optional concrete mix profile snapshot (never applied to Advanced workbook costing).
        if (! BoqCompanySettings::enabled() && array_key_exists('concrete_mix_profile_id', $row)) {
            $profileId = $row['concrete_mix_profile_id'] !== null && $row['concrete_mix_profile_id'] !== ''
                ? (int) $row['concrete_mix_profile_id']
                : null;
            if ($profileId === null) {
                $item->forceFill([
                    'concrete_mix_profile_id' => null,
                    'concrete_mix_profile_snapshot' => null,
                ]);
            } elseif ((int) $item->concrete_mix_profile_id !== $profileId || blank($item->concrete_mix_profile_snapshot)) {
                $profile = ConcreteMixProfile::query()
                    ->whereKey($profileId)
                    ->where('is_active', true)
                    ->first();
                if ($profile === null) {
                    throw ValidationException::withMessages([
                        "{$errorKey}.concrete_mix_profile_id" => 'Choose an active concrete mix profile.',
                    ]);
                }
                $item->forceFill([
                    'concrete_mix_profile_id' => $profile->id,
                    'concrete_mix_profile_snapshot' => app(ConcreteMixProfileService::class)->snapshot($profile),
                ]);
            }
        }

        $clientRateInput = $row['client_rate'] ?? null;
        // Simplified/Normal BOQ: empty Material Rate must not fall back to cost+labour selling rate
        // (Labour Rate is stored separately; commercial Amount adds it via combine flag below).
        $simplified = ! BoqCompanySettings::enabled();
        if ($simplified && ($clientRateInput === null || $clientRateInput === '')) {
            $clientRateInput = '0';
        }

        $calc = BoqCalculator::line([
            ...$costs,
            'quantity' => $quantity,
            'client_rate' => $clientRateInput,
        ], $simplified);

        $item->fill([...$calc, 'rate_analysis_id' => $analysisId]);

        // WBS: explicit → section default → leave null (Unallocated). Never invent WBS-06.
        $wbs = app(ProjectWbsService::class);
        $project = $this->projectForItem($item);
        if (array_key_exists('project_cost_head_id', $row)) {
            $rawHead = $row['project_cost_head_id'];
            $headId = ($rawHead === null || $rawHead === '') ? null : (int) $rawHead;
            if ($project !== null) {
                $headId = $wbs->assertBelongsToProject($project, $headId, "{$errorKey}.project_cost_head_id");
            }
            $item->project_cost_head_id = $headId;
        } elseif (! $item->project_cost_head_id && $item->boq_section_id) {
            $sectionDefault = BoqSection::query()->whereKey($item->boq_section_id)->value('default_project_cost_head_id');
            if ($sectionDefault) {
                $headId = (int) $sectionDefault;
                if ($project !== null) {
                    $headId = $wbs->assertBelongsToProject($project, $headId, "{$errorKey}.project_cost_head_id") ?? $headId;
                }
                $item->project_cost_head_id = $headId;
            }
        }
    }

    private function projectForItem(BoqItem $item): ?Project
    {
        $boq = $item->relationLoaded('boq') ? $item->boq : ($item->boq_id ? $item->boq()->first() : null);
        if ($boq === null) {
            return null;
        }

        return $boq->relationLoaded('project')
            ? $boq->project
            : ($boq->project_id ? Project::query()->find($boq->project_id) : null);
    }

    /**
     * Simplified BOQ only. When a material row has quantity > 0, the item rate is the
     * material cost per BOQ unit. Submitted client_rate is ignored in that case.
     * Empty or zero-quantity rows leave the existing manual/imported rate unchanged.
     */
    private function applySimplifiedMaterialRate(Boq $boq, BoqItem $item, SimpleBoqMaterialCostService $materials): void
    {
        if (BoqCompanySettings::enabled($boq->company)) {
            return;
        }

        $stored = $item->simpleMaterialCosts()->get();
        if (! $materials->drivesItemRate($stored)) {
            return;
        }

        $perUnit = $materials->totals($stored, (string) $item->quantity)['per_unit'];
        $calc = BoqCalculator::line([
            'quantity' => (string) $item->quantity,
            'material_rate' => (string) ($item->material_rate ?? '0'),
            'labour_rate' => (string) ($item->labour_rate ?? '0'),
            'equipment_rate' => (string) ($item->equipment_rate ?? '0'),
            'subcontract_rate' => (string) ($item->subcontract_rate ?? '0'),
            'margin_percent' => (string) ($item->margin_percent ?? '0'),
            'client_rate' => $perUnit,
        ], true);
        $item->fill($calc)->save();
    }

    /**
     * Rates snapshotted from an approved rate analysis: direct cost split into the four BOQ cost
     * columns, the analysis unit rate as client rate, and the margin that rate implies.
     *
     * @return array{material_rate: string, labour_rate: string, equipment_rate: string, subcontract_rate: string, margin_percent: string, client_rate: string}
     */
    public function snapshotFromAnalysis(RateAnalysis $analysis): array
    {
        $rates = RateAnalysisCalculator::perUnitRates($analysis->only([
            'output_quantity', 'material_cost', 'labour_cost', 'equipment_cost', 'subcontract_cost', 'other_cost',
        ]));
        $costRate = Decimal::sum($rates)->toRate();

        return [
            ...$rates,
            'margin_percent' => BoqCalculator::impliedMargin($costRate, $analysis->unit_rate),
            'client_rate' => $analysis->unit_rate,
        ];
    }

    /**
     * Approved analyses that may be applied to this BOQ: the project's own or the company library.
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, RateAnalysis>
     */
    public function applicableAnalyses(Boq $boq, ?array $ids = null): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return RateAnalysis::query()
            ->where('status', RateAnalysisStatus::Approved)
            ->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', $boq->project_id))
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->orderBy('code')
            ->get()
            ->keyBy('id');
    }

    public function recalculateTotals(Boq $boq): Boq
    {
        $amounts = $boq->items()->get(['cost_amount', 'client_amount']);

        $boq->forceFill([
            'total_cost_amount' => Decimal::sum($amounts->pluck('cost_amount'))->toMoney(),
            'total_client_amount' => Decimal::sum($amounts->pluck('client_amount'))->toMoney(),
        ])->save();

        return $boq;
    }

    public function submit(Boq $boq, User $user): void
    {
        $boq->assertEditable();

        if (! $boq->items()->exists()) {
            throw ValidationException::withMessages(['boq' => 'Add at least one line before submitting the BOQ.']);
        }

        if (BoqCompanySettings::enabled()) {
            app(AdvancedBoqTechnicalApprovalValidator::class)->assertReady($boq);
        }

        $this->approvals->submit($boq, $user);
    }

    /**
     * Final approval (called by the approval engine inside its transaction). The previously current
     * revision of the same BOQ becomes "revised", and planning links move to the new lines by line_uid.
     */
    public function markApproved(Boq $boq, ?int $approverId): void
    {
        DB::transaction(function () use ($boq, $approverId) {
            $boq = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();

            // Idempotent finalize: already current approved BOQ is a no-op.
            if ($boq->status === BoqStatus::Approved && $boq->is_current) {
                return;
            }

            // Enforce BoqStatus::allowedTransitions() at the service boundary (R6).
            if (! $boq->status->canTransitionTo(BoqStatus::Approved)) {
                throw ValidationException::withMessages([
                    'boq' => "A {$boq->status->label()} BOQ cannot be approved.",
                ]);
            }

            $previous = Boq::query()
                ->where('project_id', $boq->project_id)
                ->where('boq_number', $boq->boq_number)
                ->whereKeyNot($boq->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->get();

            foreach ($previous as $old) {
                // Superseding an approved current revision uses Approved → Revised (declared).
                if ($old->status === BoqStatus::Approved && ! $old->status->canTransitionTo(BoqStatus::Revised)) {
                    throw ValidationException::withMessages([
                        'boq' => "Previous BOQ revision {$old->version} cannot be marked revised.",
                    ]);
                }

                $old->forceFill(['status' => BoqStatus::Revised, 'is_current' => false])->save();
                $this->relinkTasks($old, $boq);
            }

            $boq->forceFill([
                'status' => BoqStatus::Approved,
                'is_current' => true,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();
        });
    }

    /**
     * Lock and re-check selected revisions inside the caller's transaction so eligibility cannot
     * change between the form and the insert.
     *
     * @param  list<int|string>  $ids
     * @param  list<int>  $alreadyLinked
     * @return list<int>
     */
    private function assertEligibleRevisions(Project $project, array $ids, array $alreadyLinked = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'drawing_revision_ids' => 'BOQ creation is restricted. At least one drawing must be approved before creating a BOQ for this project.',
            ]);
        }

        $revisions = DrawingRevision::query()
            ->with(['drawing' => fn ($q) => $q->withTrashed()])
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (DrawingRevision $revision) => (int) $revision->id);

        if ($revisions->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'drawing_revision_ids' => 'One or more selected drawing revisions could not be found.',
            ]);
        }

        foreach ($ids as $i => $id) {
            $revision = $revisions->get($id);
            $drawing = $revision?->drawing;
            $key = "drawing_revision_ids.{$i}";

            if ($drawing === null || $drawing->trashed() || (int) $drawing->project_id !== (int) $project->id) {
                throw ValidationException::withMessages([
                    $key => 'Each selected drawing revision must belong to this project.',
                ]);
            }

            $keepHistorical = in_array($id, $alreadyLinked, true)
                && $revision->status === DrawingRevisionStatus::Superseded;

            if ($keepHistorical) {
                continue;
            }

            if ($revision->status !== DrawingRevisionStatus::Approved
                || (int) $drawing->current_revision_id !== (int) $revision->id) {
                throw ValidationException::withMessages([
                    $key => 'Only currently approved drawing revisions of this project can be linked to a BOQ.',
                ]);
            }
        }

        return $ids;
    }

    private function relinkTasks(Boq $old, Boq $new): void
    {
        $oldUids = BoqItem::query()->where('boq_id', $old->id)->pluck('line_uid', 'id');
        if ($oldUids->isEmpty()) {
            return;
        }
        $newIds = BoqItem::query()->where('boq_id', $new->id)->pluck('id', 'line_uid');

        ProjectTask::query()
            ->where('project_id', $new->project_id)
            ->whereIn('boq_item_id', $oldUids->keys())
            ->get()
            ->each(function (ProjectTask $task) use ($oldUids, $newIds) {
                $target = $newIds->get($oldUids->get($task->boq_item_id));
                if ($target !== null) {
                    $task->boq_item_id = $target;
                    $task->save();
                }
            });
    }
}
