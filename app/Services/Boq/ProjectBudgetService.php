<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetSource;
use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\BudgetType;
use App\Enums\CostHead;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\ProjectCostHeadService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioned project budgets by budget_type:
 * - main_project: management ceiling (project create / WBS allocations)
 * - boq_estimate: technical estimate synced from BOQ costing
 *
 * Streams never overwrite each other. Cost Control uses Main only.
 */
class ProjectBudgetService
{
    private const BOQ_HEADS = [
        'material_rate' => CostHead::Material,
        'labour_rate' => CostHead::Labour,
        'equipment_rate' => CostHead::Equipment,
        'subcontract_rate' => CostHead::Subcontract,
    ];

    /**
     * Create Main Project Budget Version 1 (Draft) with WBS allocations.
     *
     * @param  array<int|string, mixed>  $amountByHeadId  project_cost_head_id => amount
     */
    public function createInitialBudget(Project $project, array $amountByHeadId = []): ProjectBudget
    {
        if (ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('budget_type', BudgetType::MainProject)
            ->exists()) {
            throw ValidationException::withMessages([
                'budget' => 'This project already has a Main Project Budget. Create a new version instead.',
            ]);
        }

        $project->loadMissing('company');
        $heads = app(ProjectCostHeadService::class)->ensureDefaults($project->company);

        $budget = new ProjectBudget;
        $budget->forceFill([
            'project_id' => $project->id,
            'budget_type' => BudgetType::MainProject,
            'version' => 1,
            'source' => BudgetSource::Manual,
            'boq_id' => null,
            'status' => BudgetStatus::Draft,
            'total_amount' => '0.00',
            'materials_sync_pending' => false,
        ])->save();

        foreach ($heads as $head) {
            $raw = $amountByHeadId[$head->id] ?? $amountByHeadId[(string) $head->id] ?? '0';
            $amount = Decimal::of((string) ($raw === null || $raw === '' ? '0' : $raw));
            if ($amount->isNegative()) {
                throw ValidationException::withMessages([
                    "initial_budget.{$head->id}" => 'Budget amounts cannot be negative.',
                ]);
            }

            $line = new ProjectBudgetLine([
                'cost_head' => CostHead::Other->value,
                'project_cost_head_id' => $head->id,
                'description' => $head->name,
                'amount' => $amount->toMoney(),
                'source_type' => BudgetLineSource::Manual->value,
            ]);
            $line->budget()->associate($budget);
            $line->save();
        }

        return $this->recalculate($budget);
    }

    /**
     * Update Main Project Budget WBS line amounts on a draft.
     *
     * @param  array<int|string, mixed>  $amountByHeadId
     */
    public function saveWbsAllocations(ProjectBudget $budget, array $amountByHeadId): ProjectBudget
    {
        if ($budget->budget_type !== BudgetType::MainProject) {
            throw ValidationException::withMessages([
                'budget' => 'WBS allocations apply to Main Project Budget only.',
            ]);
        }

        return DB::transaction(function () use ($budget, $amountByHeadId) {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            $project = $locked->project ?? Project::query()->findOrFail($locked->project_id);
            $project->loadMissing('company');
            $heads = app(ProjectCostHeadService::class)->ensureDefaults($project->company);

            foreach ($heads as $head) {
                $raw = $amountByHeadId[$head->id] ?? $amountByHeadId[(string) $head->id] ?? null;
                if ($raw === null) {
                    continue;
                }
                $amount = Decimal::of((string) ($raw === '' ? '0' : $raw));
                if ($amount->isNegative()) {
                    throw ValidationException::withMessages([
                        "wbs_allocations.{$head->id}" => 'Budget amounts cannot be negative.',
                    ]);
                }

                $line = $locked->lines()
                    ->where('project_cost_head_id', $head->id)
                    ->where('source_type', BudgetLineSource::Manual->value)
                    ->first();

                if ($line === null) {
                    $line = new ProjectBudgetLine([
                        'cost_head' => CostHead::Other->value,
                        'project_cost_head_id' => $head->id,
                        'description' => $head->name,
                        'amount' => $amount->toMoney(),
                        'source_type' => BudgetLineSource::Manual->value,
                    ]);
                    $line->budget()->associate($locked);
                    $line->save();
                } else {
                    $line->forceFill([
                        'description' => $head->name,
                        'amount' => $amount->toMoney(),
                    ])->save();
                }
            }

            return $this->recalculate($locked->fresh());
        });
    }

    /**
     * Build / refresh BOQ Estimate draft from the current approved BOQ.
     * Never writes Main Project Budget.
     *
     * @deprecated Manual Generate is removed — use publishEstimateFromApprovedBoq() on final BOQ approval.
     */
    public function generateFromBoq(Project $project, Boq $boq): ProjectBudget
    {
        if ((int) $boq->project_id !== (int) $project->id || $boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            throw ValidationException::withMessages(['boq_id' => 'Choose the current approved BOQ of this project.']);
        }

        return DB::transaction(function () use ($project, $boq) {
            $budget = $this->openFreshEstimateDraft($project, $boq);
            $this->seedSimplifiedCostHeadLines($budget, $boq);

            return app(BoqBudgetMaterialSyncService::class)->applyToDraft($budget, $boq);
        });
    }

    /**
     * After final BOQ approval: create an immutable approved BOQ Estimate snapshot.
     * Aggregates all current approved BOQs for the project. Never touches Main Project Budget.
     * Idempotent per boq_id (same approved revision → one estimate).
     * Approval is inherited from the source BOQ — no separate estimate approval step.
     */
    public function publishEstimateFromApprovedBoq(Boq $boq, ?User $approver = null): ProjectBudget
    {
        if ($boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            throw ValidationException::withMessages([
                'boq' => 'BOQ Estimate publishes only after final BOQ approval.',
            ]);
        }

        $project = $boq->project ?? Project::query()->findOrFail($boq->project_id);

        $existing = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Approved)
            ->where('boq_id', $boq->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $approverId = $approver?->id ?? $boq->approved_by;
        if ($approverId === null) {
            throw ValidationException::withMessages([
                'boq' => 'Cannot publish BOQ Estimate without a source BOQ approver.',
            ]);
        }

        $approvedAt = $boq->approved_at ?? now();

        return DB::transaction(function () use ($project, $boq, $approverId, $approvedAt) {
            $boqs = Boq::query()
                ->where('project_id', $project->id)
                ->where('status', BoqStatus::Approved)
                ->where('is_current', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($boqs->isEmpty()) {
                throw ValidationException::withMessages([
                    'boq' => 'No current approved BOQ found to publish as BOQ Estimate.',
                ]);
            }

            // Re-check idempotency under lock (double final-approval event).
            $lockedExisting = ProjectBudget::query()
                ->where('project_id', $project->id)
                ->boqEstimate()
                ->where('status', BudgetStatus::Approved)
                ->where('boq_id', $boq->id)
                ->lockForUpdate()
                ->first();

            if ($lockedExisting !== null) {
                return $lockedExisting;
            }

            $draft = $this->openFreshEstimateDraft($project, $boq);

            foreach ($boqs as $sourceBoq) {
                $this->seedSimplifiedCostHeadLines($draft, $sourceBoq);
                app(BoqBudgetMaterialSyncService::class)->appendBoqToDraft($draft, $sourceBoq);
            }

            $draft = $this->recalculate($draft->fresh());

            if (! $draft->lines()->exists()) {
                throw ValidationException::withMessages([
                    'boq' => 'Cannot publish an empty BOQ Estimate — BOQ has no costing lines.',
                ]);
            }

            return $this->finalizeEstimateInheritedApproval($draft, $boq, (int) $approverId, $approvedAt);
        });
    }

    /**
     * Mark a BOQ Estimate approved by inheriting the source BOQ final approval.
     * Not a human approval step — do not call from the budget Approve action.
     */
    private function finalizeEstimateInheritedApproval(
        ProjectBudget $budget,
        Boq $sourceBoq,
        int $approverId,
        mixed $approvedAt,
    ): ProjectBudget {
        $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

        if ($locked->budget_type !== BudgetType::BoqEstimate) {
            throw ValidationException::withMessages([
                'budget' => 'Only BOQ Estimate can inherit approval from a BOQ.',
            ]);
        }

        $locked->assertEditable();

        if (! $locked->lines()->exists()) {
            throw ValidationException::withMessages(['budget' => 'The budget has no lines.']);
        }

        ProjectBudget::query()
            ->where('project_id', $locked->project_id)
            ->where('budget_type', BudgetType::BoqEstimate)
            ->where('status', BudgetStatus::Approved)
            ->get()
            ->each(fn (ProjectBudget $old) => $old->forceFill(['status' => BudgetStatus::Superseded])->save());

        $locked->forceFill([
            'status' => BudgetStatus::Approved,
            'approved_by' => $approverId,
            'approved_at' => $approvedAt,
            'boq_id' => $sourceBoq->id,
            'source' => BudgetSource::Boq,
            'materials_sync_pending' => false,
            'materials_synced_at' => $approvedAt,
        ])->save();

        $revision = 'R'.$sourceBoq->version;
        $locked->writeAudit('boq_estimate_published', null, [
            'source_boq_id' => $sourceBoq->id,
            'source_boq_number' => $sourceBoq->boq_number,
            'source_revision' => $revision,
            'inherited_from' => "BOQ {$sourceBoq->boq_number} / {$revision}",
            'message' => "BOQ Estimate inherited approval from BOQ {$revision}",
        ]);

        return $locked->fresh();
    }

    /**
     * Blank BOQ Estimate draft for automatic publish (does not copy prior approved lines).
     */
    public function openFreshEstimateDraft(Project $project, Boq $triggerBoq): ProjectBudget
    {
        $draft = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->where('status', BudgetStatus::Draft)
            ->lockForUpdate()
            ->first();

        if ($draft !== null) {
            $draft->lines()->get()->each->delete();
            $draft->forceFill([
                'budget_type' => BudgetType::BoqEstimate,
                'source' => BudgetSource::Boq,
                'boq_id' => $triggerBoq->id,
                'total_amount' => '0.00',
                'materials_sync_pending' => false,
                'materials_synced_at' => null,
            ])->save();

            return $draft->fresh();
        }

        $latestVersion = (int) (ProjectBudget::query()->withTrashed()
            ->where('project_id', $project->id)
            ->boqEstimate()
            ->orderByDesc('version')
            ->value('version') ?? 0);

        $budget = new ProjectBudget;
        $budget->forceFill([
            'project_id' => $project->id,
            'budget_type' => BudgetType::BoqEstimate,
            'version' => $latestVersion + 1,
            'source' => BudgetSource::Boq,
            'boq_id' => $triggerBoq->id,
            'status' => BudgetStatus::Draft,
            'total_amount' => '0.00',
            'materials_sync_pending' => false,
        ])->save();

        return $budget;
    }

    /**
     * Simplified BOQ: seed Qty×rate cost-head lines when detailed material rows do not drive the rate.
     */
    private function seedSimplifiedCostHeadLines(ProjectBudget $budget, Boq $boq): void
    {
        $project = $budget->project ?? Project::query()->findOrFail($budget->project_id);
        if (BoqCompanySettings::enabled($project->company)) {
            return;
        }

        $materials = app(SimpleBoqMaterialCostService::class);
        $boq->items()->with('simpleMaterialCosts')->orderBy('sort_order')->orderBy('id')->get()
            ->each(function (BoqItem $item) use ($budget, $boq, $materials) {
                $skipMaterialHead = $materials->drivesItemRate($item->simpleMaterialCosts);
                $skipLabourHead = Decimal::of((string) ($item->labour_rate ?? '0'))->isPositive();
                foreach ($this->headAmounts($item) as $head => $amount) {
                    if ($skipMaterialHead && $head === CostHead::Material->value) {
                        continue;
                    }
                    if ($skipLabourHead && $head === CostHead::Labour->value) {
                        continue;
                    }
                    $line = new ProjectBudgetLine([
                        'cost_head' => $head,
                        'project_cost_head_id' => $item->project_cost_head_id,
                        'boq_item_id' => $item->id,
                        'source_type' => BudgetLineSource::BoqCostHead->value,
                        'source_key' => 'boq_cost_head:'.$boq->id.':'.$item->id.':'.$head,
                        'description' => mb_substr(trim(($item->item_code ? $item->item_code.' ' : '').$item->name), 0, 255),
                        'amount' => $amount,
                    ]);
                    $line->budget()->associate($budget);
                    $line->save();
                }
            });
    }

    /**
     * @return array<string, string> cost head value => amount
     */
    public function headAmounts(BoqItem $item): array
    {
        $qty = Decimal::of($item->quantity);
        $amounts = [];
        $simplified = ! BoqCompanySettings::enabled();

        foreach (self::BOQ_HEADS as $field => $head) {
            // Simplified: Material Estimate uses commercial Material Rate (client_rate), not internal cost material_rate.
            // Labour Estimate stays Qty × labour_rate (often supplied via BoqBudgetMaterialSyncService instead).
            $rate = ($simplified && $field === 'material_rate')
                ? Decimal::of((string) ($item->client_rate ?? '0'))
                : Decimal::of((string) ($item->getAttribute($field) ?? '0'));
            $amount = $qty->times($rate)->round();
            if (! $amount->isZero()) {
                $amounts[$head->value] = $amount;
            }
        }

        // Advanced/detailed: reconcile residual to cost_amount. Simplified commercial heads must not
        // be warped by internal cost_amount (which also includes labour in cost_rate).
        if ($amounts !== [] && ! $simplified) {
            $difference = Decimal::of($item->cost_amount)->minus(Decimal::sum($amounts));
            $last = array_key_last($amounts);
            $amounts[$last] = $amounts[$last]->plus($difference);
        }

        return array_map(fn (Decimal $d) => $d->toMoney(), $amounts);
    }

    /**
     * @param  array{cost_head: string, description: string, amount: string}  $data
     */
    public function saveLine(Project $project, array $data, ?ProjectBudgetLine $line = null): ProjectBudget
    {
        return DB::transaction(function () use ($project, $data, $line) {
            $type = $line?->budget?->budget_type ?? BudgetType::tryFrom((string) ($data['budget_type'] ?? '')) ?? BudgetType::MainProject;
            $budget = $line?->budget ?? $this->draftFor($project, $type);
            $budget->assertEditable();

            $line ??= new ProjectBudgetLine;
            if ($line->exists && $line->source_type instanceof BudgetLineSource
                && in_array($line->source_type->value, BudgetLineSource::syncOwnedValues(), true)) {
                throw ValidationException::withMessages([
                    'line' => 'BOQ-generated estimate lines are updated automatically on final BOQ approval, not by manual edit.',
                ]);
            }
            if ($budget->budget_type === BudgetType::BoqEstimate && ! $line->exists) {
                throw ValidationException::withMessages([
                    'line' => 'BOQ Estimate lines come from final BOQ approval. Create a new BOQ revision to change them.',
                ]);
            }

            $line->fill([
                'cost_head' => $data['cost_head'],
                'project_cost_head_id' => $data['project_cost_head_id'] ?? $line->project_cost_head_id,
                'description' => $data['description'],
                'amount' => Decimal::of($data['amount'])->toMoney(),
                'source_type' => $line->source_type?->value ?? BudgetLineSource::Manual->value,
            ]);
            $line->budget()->associate($budget);
            $line->save();

            return $this->recalculate($budget);
        });
    }

    public function deleteLine(ProjectBudgetLine $line): ProjectBudget
    {
        return DB::transaction(function () use ($line) {
            $budget = $line->budget;
            $budget->assertEditable();
            if ($line->source_type instanceof BudgetLineSource
                && in_array($line->source_type->value, BudgetLineSource::syncOwnedValues(), true)) {
                throw ValidationException::withMessages([
                    'line' => 'BOQ-generated estimate lines are removed by publishing a new BOQ Estimate on final BOQ approval.',
                ]);
            }
            $line->delete();

            return $this->recalculate($budget);
        });
    }

    /**
     * Approve Main Project Budget only. BOQ Estimate inherits approval from final BOQ approval.
     */
    public function approve(ProjectBudget $budget, User $user): ProjectBudget
    {
        if ($budget->budget_type === BudgetType::BoqEstimate) {
            throw ValidationException::withMessages([
                'budget' => 'BOQ Estimate inherits approval from the finally approved BOQ revision and cannot be approved separately.',
            ]);
        }

        return DB::transaction(function () use ($budget, $user) {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if (! $locked->lines()->exists()) {
                throw ValidationException::withMessages(['budget' => 'The budget has no lines.']);
            }

            ProjectBudget::query()
                ->where('project_id', $locked->project_id)
                ->where('budget_type', $locked->budget_type)
                ->where('status', BudgetStatus::Approved)
                ->get()
                ->each(fn (ProjectBudget $old) => $old->forceFill(['status' => BudgetStatus::Superseded])->save());

            $locked->forceFill([
                'status' => BudgetStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function recalculate(ProjectBudget $budget): ProjectBudget
    {
        $budget->forceFill([
            'total_amount' => Decimal::sum($budget->lines()->pluck('amount'))->toMoney(),
        ])->save();

        return $budget;
    }

    /**
     * Open draft for a budget type, or copy the approved version of that type.
     */
    public function draftFor(Project $project, BudgetType $type): ProjectBudget
    {
        $latest = ProjectBudget::query()->withTrashed()
            ->where('project_id', $project->id)
            ->where('budget_type', $type)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();

        if ($latest !== null && $latest->deleted_at === null && $latest->status === BudgetStatus::Draft) {
            return $latest;
        }

        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('budget_type', $type)
            ->where('status', BudgetStatus::Approved)
            ->first();

        $budget = new ProjectBudget;
        $budget->forceFill([
            'project_id' => $project->id,
            'budget_type' => $type,
            'version' => ($latest?->version ?? 0) + 1,
            'source' => $approved?->source ?? ($type === BudgetType::BoqEstimate ? BudgetSource::Boq : BudgetSource::Manual),
            'boq_id' => $approved?->boq_id,
            'status' => BudgetStatus::Draft,
            'total_amount' => $approved?->total_amount ?? '0.00',
        ])->save();

        $approved?->lines()->get()->each(function (ProjectBudgetLine $line) use ($budget) {
            $copy = $line->replicate(['project_budget_id']);
            $copy->budget()->associate($budget);
            $copy->save();
        });

        return $budget;
    }

    public function newVersion(Project $project, BudgetType $type = BudgetType::MainProject): ProjectBudget
    {
        if ($type === BudgetType::BoqEstimate) {
            throw ValidationException::withMessages([
                'budget_type' => 'BOQ Estimate versions are created automatically on final BOQ approval. Create a BOQ revision instead.',
            ]);
        }

        return DB::transaction(fn () => $this->draftFor($project, $type));
    }

    public function ensureDraft(Project $project, BudgetType $type = BudgetType::MainProject): ProjectBudget
    {
        return DB::transaction(fn () => $this->draftFor($project, $type));
    }

    public function approvedOfType(Project $project, BudgetType $type): ?ProjectBudget
    {
        return ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('budget_type', $type)
            ->where('status', BudgetStatus::Approved)
            ->first();
    }
}
