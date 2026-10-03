<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\RateAnalysisStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\RateAnalysis;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
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
    private const LINE_FIELDS = ['item_code', 'name', 'description', 'hsn_sac', 'unit_id'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{title: string}  $data
     */
    public function create(Project $project, array $data): Boq
    {
        return DB::transaction(function () use ($project, $data) {
            $boq = new Boq(['title' => $data['title']]);
            $boq->forceFill([
                'project_id' => $project->id,
                'boq_number' => $this->numbers->next('boq', $project),
                'version' => 1,
                'status' => BoqStatus::Draft,
                'is_current' => false,
            ])->save();

            return $boq;
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
            }

            return $this->recalculateTotals($boq);
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

        $calc = BoqCalculator::line([
            ...$costs,
            'quantity' => (string) ($row['quantity'] ?? '0'),
            'client_rate' => $row['client_rate'] ?? null,
        ]);

        $item->fill([...$calc, 'rate_analysis_id' => $analysisId]);
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
