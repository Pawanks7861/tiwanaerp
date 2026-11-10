<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\RateAnalysisStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\DrawingRevision;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Support\Boq\BoqCompanySettings;
use App\Services\Numbering\DocumentNumberService;
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
        'source_type', 'source_import_id', 'source_sheet', 'source_row',
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
        $section->fill([
            'parent_id' => $parentId,
            'code' => $data['code'] ?? null,
            'name' => $data['name'],
            'discipline' => $data['discipline'] ?? null,
            'sort_order' => $data['sort_order'] ?? ($section->exists
                ? $section->sort_order
                : (int) $boq->sections()->where('parent_id', $parentId)->max('sort_order') + 1),
        ])->save();

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

                $this->fillLine($item, $row, $canEditCosts, $analyses, "rows.{$i}");
                $item->boq_section_id = (int) $row['boq_section_id'];
                $item->sort_order = (int) ($row['sort_order'] ?? $i);
                $item->boq()->associate($boq);
                $item->save();

                if ($canEditCosts && array_key_exists('simple_materials', $row) && is_array($row['simple_materials'])) {
                    $materials = app(SimpleBoqMaterialCostService::class);
                    $materials->syncForItem(
                        $boq,
                        $item,
                        $row['simple_materials'],
                        auth()->user(),
                        "rows.{$i}.simple_materials",
                    );
                    $this->applySimplifiedMaterialRate($boq, $item, $materials);
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

        $source = $item->quantity_source instanceof \App\Enums\Boq\QuantitySource
            ? $item->quantity_source
            : \App\Enums\Boq\QuantitySource::tryFrom((string) ($row['quantity_source'] ?? $item->getAttribute('quantity_source') ?? 'direct'))
                ?? \App\Enums\Boq\QuantitySource::Direct;

        if (array_key_exists('quantity_source', $row) && $canEditCosts) {
            // quantity_source is managed via item workspace / measurement service; ignore silent client changes here
        }

        $quantity = (string) ($row['quantity'] ?? '0');
        if ($source === \App\Enums\Boq\QuantitySource::Measurement && $item->exists) {
            // Authoritative qty comes from approved measurement sheet — do not trust grid quantity.
            $quantity = (string) $item->quantity;
        }

        $calc = BoqCalculator::line([
            ...$costs,
            'quantity' => $quantity,
            'client_rate' => $row['client_rate'] ?? null,
        ]);

        $item->fill([...$calc, 'rate_analysis_id' => $analysisId]);
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
        ]);
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

        $this->approvals->submit($boq, $user);
    }

    /**
     * Final approval (called by the approval engine inside its transaction). The previously current
     * revision of the same BOQ becomes "revised", and planning links move to the new lines by line_uid.
     */
    public function markApproved(Boq $boq, ?int $approverId): void
    {
        DB::transaction(function () use ($boq, $approverId) {
            $previous = Boq::query()
                ->where('project_id', $boq->project_id)
                ->where('boq_number', $boq->boq_number)
                ->whereKeyNot($boq->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->get();

            foreach ($previous as $old) {
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
