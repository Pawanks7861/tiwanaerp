<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectMemberRequest;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Services\Core\CompanyDirectory;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTeamController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly CompanyDirectory $directory,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $members = $project->members()
            ->with('user:id,name,email,mobile')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (ProjectUser $m) => $m->user?->name)
            ->values()
            ->map(fn (ProjectUser $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'mobile' => $m->user?->mobile,
                'project_role' => $m->project_role->value,
                'is_manager' => (int) $project->project_manager_id === (int) $m->user_id,
            ]);

        $canManage = $request->user()->can('manageTeam', $project);

        return Inertia::render('Projects/Team', [
            'project' => ProjectHeader::for($project),
            'members' => $members->all(),
            'roles' => ProjectRole::options(),
            'candidates' => $canManage
                ? array_values(array_filter(
                    $this->directory->memberOptions(),
                    fn ($u) => ! $members->contains('user_id', $u['value']),
                ))
                : [],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(ProjectMemberRequest $request, Project $project): RedirectResponse
    {
        $validated = $request->validated();
        $this->projects->assignMember($project, (int) $validated['user_id'], ProjectRole::from($validated['project_role']));

        return back()->with('success', 'Team member added.');
    }

    public function update(ProjectMemberRequest $request, Project $project, ProjectUser $member): RedirectResponse
    {
        $this->projects->assignMember($project, (int) $member->user_id, ProjectRole::from($request->validated('project_role')));

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, Project $project, ProjectUser $member): RedirectResponse
    {
        abort_unless($request->user()->can('manageTeam', $project), 403);

        $this->projects->removeMember($project, $member);

        return back()->with('success', 'Team member removed.');
    }
}
