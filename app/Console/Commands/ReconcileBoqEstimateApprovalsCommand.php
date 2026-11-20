<?php

namespace App\Console\Commands;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\BudgetStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\ProjectBudget;
use App\Models\Core\Company;
use App\Services\Boq\ProjectBudgetService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('boq:reconcile-estimate-approvals
    {--company= : Company id (default: every company)}
    {--apply : Publish/approve matching draft estimates from their approved source BOQ}
    {--force : Do not ask for confirmation before --apply}')]
#[Description('Report (or safely repair) BOQ Estimate drafts left from the old separate-approval workflow')]
class ReconcileBoqEstimateApprovalsCommand extends Command
{
    public function handle(ProjectBudgetService $budgets, CurrentCompany $tenancy): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->option('force') && ! $this->confirm('Apply inheritance to matching draft BOQ Estimates? Historical approved rows stay untouched.')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $reported = 0;
        $repaired = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $tenancy->runAs($company, function () use ($company, $budgets, $apply, &$reported, &$repaired, &$skipped) {
                $drafts = ProjectBudget::query()
                    ->boqEstimate()
                    ->where('status', BudgetStatus::Draft)
                    ->where('company_id', $company->id)
                    ->orderBy('project_id')
                    ->orderBy('id')
                    ->get();

                if ($drafts->isEmpty()) {
                    $this->line("Company #{$company->id} {$company->name}: no draft BOQ Estimates.");

                    return;
                }

                $rows = [];
                foreach ($drafts as $draft) {
                    $boq = $draft->boq_id
                        ? Boq::query()->find($draft->boq_id)
                        : null;

                    $action = 'orphan_draft';
                    $note = 'No source BOQ / not finally approved — left untouched.';

                    if ($boq !== null && $boq->status === BoqStatus::Approved && $boq->is_current) {
                        $already = ProjectBudget::query()
                            ->where('project_id', $draft->project_id)
                            ->boqEstimate()
                            ->where('status', BudgetStatus::Approved)
                            ->where('boq_id', $boq->id)
                            ->exists();

                        if ($already) {
                            $action = 'stale_draft';
                            $note = 'Approved estimate already exists for this BOQ; draft is leftover.';
                        } else {
                            $action = 'eligible';
                            $note = 'Source BOQ finally approved — eligible to inherit approval.';
                        }
                    }

                    $reported++;
                    $rows[] = [
                        $draft->project_id,
                        $draft->id,
                        'v'.$draft->version,
                        $boq?->boq_number ?? '—',
                        $boq ? 'R'.$boq->version : '—',
                        $action,
                        $note,
                    ];

                    if (! $apply || $action !== 'eligible') {
                        if ($apply && $action !== 'eligible') {
                            $skipped++;
                        }

                        continue;
                    }

                    $budgets->publishEstimateFromApprovedBoq($boq->fresh());
                    $repaired++;
                }

                $this->line("Company #{$company->id} {$company->name}:");
                $this->table(
                    ['Project', 'Estimate', 'Version', 'BOQ', 'Rev', 'Action', 'Note'],
                    $rows,
                );
            });
        }

        $this->newLine();
        $this->info("Reported {$reported} draft estimate(s).".($apply ? " Repaired {$repaired}, skipped {$skipped}." : ' Dry-run only (pass --apply to repair).'));

        return self::SUCCESS;
    }
}
