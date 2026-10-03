<?php

namespace App\Services\Projects;

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Models\Projects\Site;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $data  validated input (code optional)
     */
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $code = trim((string) ($data['code'] ?? ''));

            $project = new Project;
            $project->fill(['code' => $code !== '' ? strtoupper($code) : $this->numbers->next('project_code')] + $data);
            $project->forceFill([
                'project_number' => $this->numbers->next('project'),
                'status' => ProjectStatus::Planning,
            ])->save();

            if ($project->project_manager_id) {
                $this->assignMember($project, (int) $project->project_manager_id, ProjectRole::Manager);
            }

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            if (isset($data['code'])) {
                $data['code'] = strtoupper(trim($data['code']));
            }

            $project->fill($data)->save();

            if ($project->wasChanged('project_manager_id') && $project->project_manager_id) {
                $this->assignMember($project, (int) $project->project_manager_id, ProjectRole::Manager);
            }

            return $project;
        });
    }

    public function changeStatus(Project $project, ProjectStatus $target): Project
    {
        if (! $project->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "A {$project->status->label()} project cannot be moved to {$target->label()}.",
            ]);
        }

        $project->forceFill([
            'status' => $target,
            'actual_end_date' => $target === ProjectStatus::Completed
                ? ($project->actual_end_date ?? now()->toDateString())
                : $project->actual_end_date,
        ])->save();

        return $project;
    }

    /**
     * Add or update a team member; re-activates a previously removed member.
     */
    public function assignMember(Project $project, int $userId, ProjectRole $role): ProjectUser
    {
        $member = ProjectUser::query()->firstOrNew(['project_id' => $project->id, 'user_id' => $userId]);
        $member->project_role = $role;
        $member->is_active = true;
        $member->save();

        return $member;
    }

    public function removeMember(Project $project, ProjectUser $member): void
    {
        if ((int) $project->project_manager_id === (int) $member->user_id) {
            throw ValidationException::withMessages([
                'member' => 'The project manager cannot be removed from the team. Change the project manager first.',
            ]);
        }

        $member->is_active = false;
        $member->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSite(Project $project, array $data): Site
    {
        $site = new Site($data);
        $site->project()->associate($project);
        $site->save();

        return $site;
    }

    public function deleteSite(Site $site): void
    {
        if ($site->isInUse()) {
            throw ValidationException::withMessages([
                'site' => 'This site is in use (warehouses or records reference it). Deactivate it instead.',
            ]);
        }

        $site->delete();
    }
}
