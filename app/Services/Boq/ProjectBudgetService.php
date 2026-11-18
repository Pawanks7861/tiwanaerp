<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetLineSource;
use App\Enums\Boq\BudgetSource;
use App\Enums\Boq\BudgetStatus;
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
 * Versioned project budgets. A draft budget is rebuilt in place; once approved, any change
 * produces a new draft version and the approved one is superseded only when the new one is approved.
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
     * Create Budget Version 1 (Draft) with WBS / Project Cost Head allocations for a new project.
     *
     * @param  array<int|string, mixed>  $amountByHeadId  project_cost_head_id => amount
     */
    public function createInitialBudget(Project $project, array $amountByHeadId = []): ProjectBudget
    {
        if (ProjectBudget::query()->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages([
                'budget' => 'This project already has a budget. Create a new version instead.',
            ]);
        }

        $project->loadMissing('company');
        $heads = app(ProjectCostHeadService::class)->ensureDefaults($project->company);

        $budget = new ProjectBudget;
        $budget->forceFill([
            'project_id' => $project->id,
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
     * Build budget lines from the current approved BOQ: one line per BOQ line and cost head.
     */
    public function generateFromBoq(Project $project, Boq $boq): ProjectBudget
    {
        if ((int) $boq->project_id !== (int) $project->id || $boq->status !== BoqStatus::Approved || ! $boq->is_current) {
            throw ValidationException::withMessages(['boq_id' => 'Choose the current approved BOQ of this project.']);
        }

        return DB::transaction(function () use ($project, $boq) {
            $budget = $this->draftFor($project);
            $budget->forceFill([
                'source' => BudgetSource::Boq,
                'boq_id' => $boq->id,
                'materials_sync_pending' => false,
            ])->save();

            $budget->lines()->get()->each->delete();

            // Advanced BOQ: workbook cost components + BBS are the only estimate source.
            // Do not also roll up item material_rate / labour_rate cost heads (double-count).
            if (! BoqCompanySettings::enabled($project->company)) {
                $materials = app(SimpleBoqMaterialCostService::class);
                $boq->items()->with('simpleMaterialCosts')->orderBy('sort_order')->orderBy('id')->get()
                    ->each(function (BoqItem $item) use ($budget, $materials) {
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
                                'description' => mb_substr(trim(($item->item_code ? $item->item_code.' ' : '').$item->name), 0, 255),
                                'amount' => $amount,
                            ]);
                            $line->budget()->associate($budget);
                            $line->save();
                        }
                    });

                $budget = $this->recalculate($budget);
            }

            return app(BoqBudgetMaterialSyncService::class)->applyToDraft($budget, $boq);
        });
    }

    /**
     * Split a BOQ line's cost amount by head. Each head is rounded to paise; any rounding difference
     * goes to the last non-zero head so the heads always add up to the line's cost_amount exactly.
     *
     * @return array<string, string> cost head value => amount
     */
    public function headAmounts(BoqItem $item): array
    {
        $qty = Decimal::of($item->quantity);
        $amounts = [];
        foreach (self::BOQ_HEADS as $field => $head) {
            $amount = $qty->times($item->getAttribute($field))->round();
            if (! $amount->isZero()) {
                $amounts[$head->value] = $amount;
            }
        }

        if ($amounts !== []) {
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
            $budget = $line?->budget ?? $this->draftFor($project);
            $budget->assertEditable();

            $line ??= new ProjectBudgetLine;
            if ($line->exists && $line->source_type instanceof BudgetLineSource
                && in_array($line->source_type->value, BudgetLineSource::syncOwnedValues(), true)) {
                throw ValidationException::withMessages([
                    'line' => 'BOQ-generated budget lines are updated by Sync from BOQ, not manual edit.',
                ]);
            }
            $line->fill([
                'cost_head' => $data['cost_head'],
                'project_cost_head_id' => array_key_exists('project_cost_head_id', $data)
                    ? ($data['project_cost_head_id'] ?: null)
                    : $line->project_cost_head_id,
                'description' => $data['description'],
                'amount' => Decimal::of($data['amount'])->toMoney(),
                'source_type' => BudgetLineSource::Manual->value,
                'source_key' => null,
                'material_id' => null,
                'material_code' => null,
                'material_name' => null,
                'unit' => null,
                'quantity' => null,
                'rate' => null,
                'sources' => null,
                'boq_item_id' => $line->exists ? $line->boq_item_id : null,
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
            $line->delete();

            return $this->recalculate($budget);
        });
    }

    public function approve(ProjectBudget $budget, User $user): ProjectBudget
    {
        return DB::transaction(function () use ($budget, $user) {
            $locked = ProjectBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if (! $locked->lines()->exists()) {
                throw ValidationException::withMessages(['budget' => 'The budget has no lines.']);
            }

            ProjectBudget::query()
                ->where('project_id', $locked->project_id)
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
     * The project's open draft budget, or a new draft version that starts as a copy of the
     * approved budget (so editing an approved budget never overwrites it).
     */
    private function draftFor(Project $project): ProjectBudget
    {
        $latest = ProjectBudget::query()->withTrashed()
            ->where('project_id', $project->id)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->first();

        if ($latest !== null && $latest->deleted_at === null && $latest->status === BudgetStatus::Draft) {
            return $latest;
        }

        $approved = ProjectBudget::query()
            ->where('project_id', $project->id)
            ->where('status', BudgetStatus::Approved)
            ->first();

        $budget = new ProjectBudget;
        $budget->forceFill([
            'project_id' => $project->id,
            'version' => ($latest?->version ?? 0) + 1,
            'source' => $approved?->source ?? BudgetSource::Manual,
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

    /**
     * Start a new draft version explicitly (copy of the approved budget, if any).
     */
    public function newVersion(Project $project): ProjectBudget
    {
        return DB::transaction(function () use ($project) {
            $budget = $this->draftFor($project);

            $approvedPending = ProjectBudget::query()
                ->where('project_id', $project->id)
                ->where('status', BudgetStatus::Approved)
                ->where('materials_sync_pending', true)
                ->exists();

            if ($approvedPending && $budget->boq_id) {
                $boq = Boq::query()->find($budget->boq_id);
                if ($boq !== null) {
                    return app(BoqBudgetMaterialSyncService::class)->applyToDraft($budget, $boq);
                }
            }

            return $budget;
        });
    }

    /**
     * Open draft budget for the project (creates a new version when the latest is approved).
     */
    public function ensureDraft(Project $project): ProjectBudget
    {
        return DB::transaction(fn () => $this->draftFor($project));
    }
}
