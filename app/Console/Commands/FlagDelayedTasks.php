<?php

namespace App\Console\Commands;

use App\Enums\Planning\TaskStatus;
use App\Models\Core\Company;
use App\Models\Planning\ProjectTask;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('planning:flag-delays {--company= : Company id (default: every active company)} {--dry-run : Only count}')]
#[Description('Mark in-progress tasks past their planned finish as delayed (completed and on-hold tasks are never touched)')]
class FlagDelayedTasks extends Command
{
    public function handle(CurrentCompany $tenancy): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id), fn ($q) => $q->where('is_active', true))
            ->orderBy('id')->get();

        foreach ($companies as $company) {
            $count = $tenancy->runAs($company, function () {
                $overdue = ProjectTask::query()
                    ->where('status', TaskStatus::InProgress)
                    ->whereNotNull('planned_finish')
                    ->whereDate('planned_finish', '<', now()->toDateString())
                    ->orderBy('id')
                    ->get();

                if (! $this->option('dry-run')) {
                    $overdue->each(fn (ProjectTask $task) => $task->forceFill(['status' => TaskStatus::Delayed])->save());
                }

                return $overdue->count();
            });

            $this->line("Company #{$company->id}: {$count} task(s) ".($this->option('dry-run') ? 'would be ' : '').'flagged delayed.');
        }

        return self::SUCCESS;
    }
}
