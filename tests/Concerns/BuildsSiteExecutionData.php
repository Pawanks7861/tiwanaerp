<?php

namespace Tests\Concerns;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\SiteDiary;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\SiteExecution\DprService;
use App\Services\SiteExecution\SiteDiaryService;

/**
 * Phase 5 fixtures on top of the project team: a site, a task "1.1 Raft concrete" (planned 100 Cum,
 * due in 30 days), cement, a mason trade, a mixer type and a second project "Tower B".
 * The site engineer writes and submits diaries; the PM reviews, approves diaries and DPRs.
 */
trait BuildsSiteExecutionData
{
    use BuildsProjectPlanningData;

    public Site $site;

    public ProjectTask $task;

    public Project $otherProject;

    public Material $cement;

    public int $mason;

    public int $mixer;

    public function setUpSiteExecution(): void
    {
        $this->setUpProjectTeam();
        $this->otherProject = $this->makeTeamProject('Tower B');
        $this->task = $this->makeTask(['wbs_code' => '1.1', 'name' => 'Raft concrete', 'planned_qty' => '100']);

        $this->inCompany($this->company, function () {
            $site = new Site(['name' => 'Main site', 'is_active' => true]);
            $site->forceFill(['project_id' => $this->project->id])->save();
            $this->site = $site;
            $this->cement = Material::query()->firstOrCreate(['code' => 'CEM53'], ['name' => 'Cement OPC 53', 'unit_id' => $this->unitId('Bag')]);
            $this->mason = (int) LabourTrade::query()->where('name', 'Mason')->value('id');
            $this->mixer = (int) EquipmentType::query()->where('name', 'Concrete Mixer')->value('id');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function makeTask(array $attributes = [], ?Project $project = null): ProjectTask
    {
        $project ??= $this->project;
        $unit = $this->unitId();

        return $this->inCompany($this->company, function () use ($attributes, $project, $unit) {
            $task = new ProjectTask;
            $task->forceFill($attributes + [
                'project_id' => $project->id,
                'wbs_code' => '9.'.random_int(1, 999999),
                'name' => 'Task',
                'unit_id' => $unit,
                'planned_start' => now()->subDays(5)->toDateString(),
                'planned_finish' => now()->addDays(30)->toDateString(),
            ])->save();

            return $task->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function makeDiary(array $data = [], ?User $author = null, ?Project $project = null): SiteDiary
    {
        $project ??= $this->project;
        $this->actingAs($author ?? $this->engineer);

        return $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->create($project, $data + [
            'diary_date' => now()->toDateString(),
            'site_id' => $project->is($this->project) ? $this->site->id : null,
            'work_performed' => 'Raft concreting',
            'work_items' => [['task_id' => $this->task->id, 'quantity' => '10', 'unit_id' => $this->unitId()]],
        ]));
    }

    /**
     * Engineer submits → PM reviews → PM approves.
     */
    public function approveDiary(SiteDiary $diary): SiteDiary
    {
        return $this->inCompany($this->company, function () use ($diary) {
            $service = app(SiteDiaryService::class);
            $service->submit($diary, $this->engineer);
            $service->review($diary, $this->pm);
            $service->approve($diary, $this->pm);

            return $diary->fresh();
        });
    }

    public function makeDpr(?string $date = null, ?Project $project = null): Dpr
    {
        $this->actingAs($this->engineer);

        return $this->inCompany($this->company, fn () => app(DprService::class)->create($project ?? $this->project, [
            'dpr_date' => $date ?? now()->toDateString(),
        ]));
    }

    /**
     * Engineer submits → PM approves (the approval posts the progress).
     */
    public function approveDpr(Dpr $dpr): Dpr
    {
        return $this->inCompany($this->company, function () use ($dpr) {
            app(DprService::class)->submit($dpr->fresh(), $this->engineer);
            app(ApprovalService::class)->approve($dpr->fresh()->pendingApprovalRequest(), $this->pm);

            return $dpr->fresh();
        });
    }

    /**
     * Diary for $qty of the task on $date, approved, then a DPR for the date, approved.
     */
    public function postProgress(string $qty, ?string $date = null, ?ProjectTask $task = null): Dpr
    {
        $date ??= now()->toDateString();
        $task ??= $this->task;
        $this->approveDiary($this->makeDiary([
            'diary_date' => $date,
            'work_items' => [['task_id' => $task->id, 'quantity' => $qty, 'unit_id' => $task->unit_id]],
        ]));

        return $this->approveDpr($this->makeDpr($date));
    }

    public function approvedBoqLine(): BoqItem
    {
        $boq = $this->approveBoq($this->makeBoq());

        return $this->inCompany($this->company, fn () => BoqItem::query()->where('boq_id', $boq->id)->where('item_code', 'A.1')->firstOrFail());
    }

    public function currentBoq(): Boq
    {
        return $this->inCompany($this->company, fn () => Boq::query()->where('project_id', $this->project->id)->where('is_current', true)->firstOrFail());
    }

    public function task(): ProjectTask
    {
        return $this->inCompany($this->company, fn () => $this->task->fresh());
    }
}
