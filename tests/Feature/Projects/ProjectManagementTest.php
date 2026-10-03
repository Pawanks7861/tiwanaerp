<?php

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Models\Projects\Site;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;

beforeEach(function () {
    $this->company = $this->createCompany();
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->pm = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
});

function makeProject($test, array $data = []): Project
{
    return $test->inCompany($test->company, fn () => app(ProjectService::class)->create($data + ['name' => 'Tower A']));
}

test('creating a project assigns its number and code from the numbering engine', function () {
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.store'), [
            'name' => 'Greenfield Residency',
            'project_manager_id' => $this->pm->id,
            'contract_value' => '12500000.50',
            'state_code' => '03',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $project = $this->inCompany($this->company, fn () => Project::query()->sole());

    expect($project->project_number)->toBe('PRJ-'.now()->format('Y').'-0001')
        ->and($project->code)->toBe('PRJ001')
        ->and($project->status)->toBe(ProjectStatus::Planning)
        ->and($project->contract_value)->toBe('12500000.50')
        ->and($project->created_by)->toBe($this->admin->id)
        ->and($this->pm->isProjectMember($project))->toBeTrue();
});

test('a custom project code must be unique within the company', function () {
    makeProject($this, ['code' => 'TWR']);

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.store'), ['name' => 'Second', 'code' => 'twr'])
        ->assertSessionHasErrors('code');
});

test('project manager must be a member of the company', function () {
    $outsider = User::factory()->create();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.store'), ['name' => 'X', 'project_manager_id' => $outsider->id])
        ->assertSessionHasErrors('project_manager_id');
});

test('users without projects.view_all only see projects they are assigned to', function () {
    $mine = makeProject($this, ['name' => 'Assigned Project']);
    makeProject($this, ['name' => 'Other Project']);
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($mine, $this->engineer->id, ProjectRole::Engineer));

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.name', 'Assigned Project'));

    $this->actingInCompany($this->admin, $this->company)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->has('projects.data', 2));
});

test('a non-member cannot open a project or its sub pages', function () {
    $project = makeProject($this);

    $as = $this->actingInCompany($this->engineer, $this->company);
    $as->get(route('projects.show', $project))->assertForbidden();
    $as->get(route('projects.sites.index', $project))->assertForbidden();
    $as->get(route('projects.team.index', $project))->assertForbidden();
});

test('removed team members lose access', function () {
    $project = makeProject($this);
    $member = $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($project, $this->engineer->id, ProjectRole::Engineer));

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.show', $project))->assertOk();

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('projects.team.destroy', [$project, $member]))
        ->assertSessionHasNoErrors();

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.show', $project))->assertForbidden();
});

test('site engineers cannot create projects or edit project details', function () {
    $project = makeProject($this);
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($project, $this->engineer->id, ProjectRole::Engineer));

    $as = $this->actingInCompany($this->engineer, $this->company);
    $as->post(route('projects.store'), ['name' => 'Nope'])->assertForbidden();
    $as->put(route('projects.update', $project), ['name' => 'Renamed', 'code' => $project->code])->assertForbidden();
});

test('status changes follow the allowed transitions', function () {
    $project = makeProject($this);
    $as = $this->actingInCompany($this->admin, $this->company);

    $as->patch(route('projects.status', $project), ['status' => 'closed'])->assertSessionHasErrors('status');
    $as->patch(route('projects.status', $project), ['status' => 'active'])->assertSessionHasNoErrors();
    $as->patch(route('projects.status', $project), ['status' => 'completed'])->assertSessionHasNoErrors();

    $project = $this->inCompany($this->company, fn () => $project->fresh());
    expect($project->status)->toBe(ProjectStatus::Completed)
        ->and($project->actual_end_date)->not->toBeNull();
});

test('sites are managed inside their project and cannot be reached through another project', function () {
    $tower = makeProject($this, ['name' => 'Tower']);
    $mall = makeProject($this, ['name' => 'Mall']);
    $as = $this->actingInCompany($this->admin, $this->company);

    $as->post(route('projects.sites.store', $tower), ['name' => 'Main Gate', 'latitude' => '30.9010000', 'longitude' => '75.8573000', 'geofence_radius_m' => 150])
        ->assertSessionHasNoErrors();

    $site = $this->inCompany($this->company, fn () => Site::query()->sole());
    expect($site->project_id)->toBe($tower->id)->and($site->latitude)->toBe('30.9010000');

    $as->put(route('projects.sites.update', [$mall, $site]), ['name' => 'Moved'])->assertNotFound();
    $as->delete(route('projects.sites.destroy', [$mall, $site]))->assertNotFound();

    $as->put(route('projects.sites.update', [$tower, $site]), ['name' => 'Main Gate 2', 'is_active' => true])->assertSessionHasNoErrors();
    $as->delete(route('projects.sites.destroy', [$tower, $site]))->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Site::query()->count()))->toBe(0);
});

test('the project manager cannot be removed from the team', function () {
    $project = makeProject($this, ['project_manager_id' => $this->pm->id]);
    $pmMember = ProjectUser::query()->where('project_id', $project->id)->where('user_id', $this->pm->id)->sole();

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('projects.team.destroy', [$project, $pmMember]))
        ->assertSessionHasErrors('member');
});

test('team members must belong to the company', function () {
    $project = makeProject($this);
    $outsider = User::factory()->create();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.team.store', $project), ['user_id' => $outsider->id, 'project_role' => 'engineer'])
        ->assertSessionHasErrors('user_id');
});

test('a team member id from another project is not found', function () {
    $tower = makeProject($this, ['name' => 'Tower']);
    $mall = makeProject($this, ['name' => 'Mall']);
    $member = $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($tower, $this->engineer->id, ProjectRole::Engineer));

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('projects.team.destroy', [$mall, $member]))
        ->assertNotFound();
});
