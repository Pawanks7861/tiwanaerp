<?php

use App\Enums\Planning\DependencyType;
use App\Enums\Planning\MilestoneStatus;
use App\Enums\Planning\TaskStatus;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Services\Planning\PlanningService;
use App\Services\Planning\TaskDependencyService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProjectPlanningData;

uses(BuildsProjectPlanningData::class);

beforeEach(function () {
    $this->setUpProjectTeam();
});

function planTask($test, array $data, $project = null): ProjectTask
{
    return $test->inCompany($test->company, fn () => app(PlanningService::class)->saveTask($project ?? $test->project, $data));
}

function planLink($test, ProjectTask $successor, ProjectTask $predecessor, string $type = 'FS', int $lag = 0): TaskDependency
{
    return $test->inCompany($test->company, fn () => app(TaskDependencyService::class)
        ->add($successor->fresh(), $predecessor->fresh(), DependencyType::from($type), $lag));
}

test('WBS codes are suggested per level and must be unique in the project', function () {
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Substructure'])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Superstructure'])->assertSessionHasNoErrors();
    $parent = $this->inCompany($this->company, fn () => ProjectTask::query()->where('wbs_code', '1')->sole());

    $as->getJson(route('projects.planning.tasks.suggest-wbs', [$this->project, 'parent_id' => $parent->id]))
        ->assertOk()->assertExactJson(['wbs_code' => '1.1']);
    $as->getJson(route('projects.planning.tasks.suggest-wbs', $this->project))->assertExactJson(['wbs_code' => '3']);

    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Footings', 'parent_id' => $parent->id])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Custom', 'wbs_code' => 'ext.a'])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Duplicate', 'wbs_code' => 'EXT.A'])->assertSessionHasErrors('wbs_code');
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Bad', 'wbs_code' => '1..2'])->assertSessionHasErrors('wbs_code');

    expect($this->inCompany($this->company, fn () => ProjectTask::query()->orderBy('id')->pluck('wbs_code')->all()))
        ->toBe(['1', '2', '1.1', 'EXT.A']);

    $mall = $this->makeTeamProject('Mall');
    expect(planTask($this, ['name' => 'Mall works', 'wbs_code' => '1'], $mall)->wbs_code)->toBe('1');
});

test('a deleted task frees its WBS code', function () {
    $task = planTask($this, ['name' => 'Temporary', 'wbs_code' => '9']);

    $this->actingInCompany($this->pm, $this->company)
        ->delete(route('projects.planning.tasks.destroy', [$this->project, $task]))
        ->assertSessionHasNoErrors();

    expect(planTask($this, ['name' => 'Again', 'wbs_code' => '9'])->wbs_code)->toBe('9')
        ->and($this->inCompany($this->company, fn () => ProjectTask::onlyTrashed()->sole()->wbs_code))->toBe('9~'.$task->id);
});

test('planned dates give the duration and finish cannot precede start', function () {
    $as = $this->actingInCompany($this->pm, $this->company);
    $as->post(route('projects.planning.tasks.store', $this->project), [
        'name' => 'Excavation', 'planned_start' => '2026-11-01', 'planned_finish' => '2026-11-10', 'planned_qty' => '125.5', 'unit_id' => $this->unitId(),
    ])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.tasks.store', $this->project), [
        'name' => 'Backwards', 'planned_start' => '2026-11-10', 'planned_finish' => '2026-11-01',
    ])->assertSessionHasErrors('planned_finish');

    $task = $this->inCompany($this->company, fn () => ProjectTask::query()->sole());
    expect($task->duration_days)->toBe(10)
        ->and($task->planned_qty)->toBe('125.5000')
        ->and($task->status)->toBe(TaskStatus::NotStarted)
        ->and($task->progress_percent)->toBe('0.0000')
        ->and($task->completed_qty)->toBe('0.0000');
});

test('parents must be tasks of the same project and cannot create a loop', function () {
    $mall = $this->makeTeamProject('Mall');
    $foreign = planTask($this, ['name' => 'Mall task'], $mall);
    $parent = planTask($this, ['name' => 'Parent']);
    $child = planTask($this, ['name' => 'Child', 'parent_id' => $parent->id]);
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'X', 'parent_id' => $foreign->id])
        ->assertSessionHasErrors('parent_id');
    $as->put(route('projects.planning.tasks.update', [$this->project, $parent]), ['name' => 'Parent', 'wbs_code' => $parent->wbs_code, 'parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');
    $as->put(route('projects.planning.tasks.update', [$this->project, $foreign]), ['name' => 'Hijack'])
        ->assertNotFound();
});

test('tasks link only to lines of the current approved BOQ of the same project', function () {
    $approved = $this->approveBoq($this->makeBoq());
    $draft = $this->makeBoq(title: 'Draft BOQ');
    $mall = $this->makeTeamProject('Mall');
    $mallBoq = $this->approveBoq($this->makeBoq(project: $mall));

    [$good, $draftItem, $mallItem] = $this->inCompany($this->company, fn () => [
        $approved->items()->orderBy('sort_order')->first(), $draft->items()->first(), $mallBoq->items()->first(),
    ]);
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Draft link', 'boq_item_id' => $draftItem->id])
        ->assertSessionHasErrors('boq_item_id');
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Other project', 'boq_item_id' => $mallItem->id])
        ->assertSessionHasErrors('boq_item_id');
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Excavation', 'boq_item_id' => $good->id])
        ->assertSessionHasNoErrors();

    $as->get(route('projects.planning.tasks.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Tasks')
            ->has('options.boqItems', 2)
            ->where('options.boqItems.0.value', $good->id)
            ->where('options.boqItems.0.description', '12.5000 Cum · BOQ-PRJ001-001 v1')
            ->where('tasks.0.boq_item.item_code', 'A.1'));
});

test('the assignee must be an active member of the project', function () {
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'X', 'assigned_to' => $outsider->id])
        ->assertSessionHasErrors('assigned_to');
    $as->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Y', 'assigned_to' => $this->engineer->id])
        ->assertSessionHasNoErrors();
});

test('status changes follow the allowed transitions and record actual dates', function () {
    $task = planTask($this, ['name' => 'Excavation']);
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->patch(route('projects.planning.tasks.status', [$this->project, $task]), ['status' => 'completed'])->assertSessionHasErrors('status');
    $as->patch(route('projects.planning.tasks.status', [$this->project, $task]), ['status' => 'in_progress'])->assertSessionHasNoErrors();
    $task = $this->inCompany($this->company, fn () => $task->fresh());
    expect($task->status)->toBe(TaskStatus::InProgress)
        ->and($task->actual_start?->toDateString())->toBe(now()->toDateString())
        ->and($task->actual_finish)->toBeNull();

    $as->patch(route('projects.planning.tasks.status', [$this->project, $task]), ['status' => 'completed'])->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => $task->fresh()->actual_finish?->toDateString()))->toBe(now()->toDateString());

    $as->put(route('projects.planning.tasks.update', [$this->project, $task]), ['name' => 'Renamed'])->assertForbidden();
    $as->delete(route('projects.planning.tasks.destroy', [$this->project, $task]))->assertForbidden();
});

test('dependencies reject self links, duplicates, cycles, parent links and other projects', function () {
    $a = planTask($this, ['name' => 'A']);
    $b = planTask($this, ['name' => 'B']);
    $c = planTask($this, ['name' => 'C']);
    $parent = planTask($this, ['name' => 'Parent']);
    $child = planTask($this, ['name' => 'Child', 'parent_id' => $parent->id]);
    $mallTask = planTask($this, ['name' => 'Mall task'], $this->makeTeamProject('Mall'));

    $link = planLink($this, $b, $a, 'SS', 2);
    planLink($this, $c, $b);
    expect($link->type)->toBe(DependencyType::StartToStart)->and($link->lag_days)->toBe(2);

    expect(fn () => planLink($this, $a, $a))->toThrow(ValidationException::class, 'A task cannot depend on itself.')
        ->and(fn () => planLink($this, $b, $a))->toThrow(ValidationException::class, 'This dependency already exists.')
        ->and(fn () => planLink($this, $a, $c))->toThrow(ValidationException::class, 'This dependency would create a circular chain of tasks.')
        ->and(fn () => planLink($this, $child, $parent))->toThrow(ValidationException::class, 'A task cannot depend on its own parent or sub-task.')
        ->and(fn () => planLink($this, $a, $mallTask))->toThrow(ValidationException::class, 'Both tasks must belong to the same project.');

    $as = $this->actingInCompany($this->pm, $this->company);
    $as->post(route('projects.planning.tasks.dependencies.store', [$this->project, $a]), ['predecessor_id' => $mallTask->id, 'type' => 'FS'])
        ->assertSessionHasErrors('predecessor_id');
    $as->post(route('projects.planning.tasks.dependencies.store', [$this->project, $a]), ['predecessor_id' => $c->id, 'type' => 'FS'])
        ->assertSessionHasErrors('predecessor_id');
    $as->post(route('projects.planning.tasks.dependencies.store', [$this->project, $parent]), ['predecessor_id' => $c->id, 'type' => 'FF', 'lag_days' => -3])
        ->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => TaskDependency::query()->count()))->toBe(3);

    $as->delete(route('projects.planning.tasks.dependencies.destroy', [$this->project, $a, $link]))->assertNotFound();
    $as->delete(route('projects.planning.tasks.dependencies.destroy', [$this->project, $b, $link]))->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => TaskDependency::query()->count()))->toBe(2);
});

test('a task with sub-tasks cannot be deleted and deleting a task removes its links', function () {
    $parent = planTask($this, ['name' => 'Parent']);
    $child = planTask($this, ['name' => 'Child', 'parent_id' => $parent->id]);
    $other = planTask($this, ['name' => 'Other']);
    planLink($this, $other, $child);
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->delete(route('projects.planning.tasks.destroy', [$this->project, $parent]))->assertSessionHasErrors('task');
    $as->delete(route('projects.planning.tasks.destroy', [$this->project, $child]))->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => TaskDependency::query()->count()))->toBe(0);
});

test('milestone billing cannot exceed 100 percent', function () {
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->post(route('projects.planning.milestones.store', $this->project), ['name' => 'Plinth', 'billing_percent' => '40.5'])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.milestones.store', $this->project), ['name' => 'Roof', 'billing_percent' => '59.5'])->assertSessionHasNoErrors();
    $as->post(route('projects.planning.milestones.store', $this->project), ['name' => 'Handover', 'billing_percent' => '0.0001'])->assertSessionHasErrors('billing_percent');

    $roof = $this->inCompany($this->company, fn () => ProjectMilestone::query()->where('name', 'Roof')->sole());
    $as->put(route('projects.planning.milestones.update', [$this->project, $roof]), ['name' => 'Roof', 'billing_percent' => '59.4'])->assertSessionHasNoErrors();

    $as->get(route('projects.planning.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Milestones')->where('billing_total', '99.9000'));
});

test('milestones can be completed and cannot be deleted while tasks use them', function () {
    $milestone = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveMilestone($this->project, ['name' => 'Plinth', 'due_date' => '2026-12-31']));
    $task = planTask($this, ['name' => 'Footings', 'milestone_id' => $milestone->id]);
    $as = $this->actingInCompany($this->pm, $this->company);

    $as->patch(route('projects.planning.milestones.complete', [$this->project, $milestone]), ['completed' => true])->assertSessionHasNoErrors();
    $milestone = $this->inCompany($this->company, fn () => $milestone->fresh());
    expect($milestone->status)->toBe(MilestoneStatus::Completed)->and($milestone->completed_at)->not->toBeNull();

    $as->delete(route('projects.planning.milestones.destroy', [$this->project, $milestone]))->assertSessionHasErrors('milestone');

    $mall = $this->makeTeamProject('Mall');
    $foreignMilestone = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveMilestone($mall, ['name' => 'Mall plinth']));
    $as->put(route('projects.planning.tasks.update', [$this->project, $task]), ['name' => 'Footings', 'wbs_code' => $task->wbs_code, 'milestone_id' => $foreignMilestone->id])
        ->assertSessionHasErrors('milestone_id');
});

test('the gantt endpoint returns scheduled tasks with their dependencies', function () {
    $a = planTask($this, ['name' => 'Excavation', 'planned_start' => '2026-11-01', 'planned_finish' => '2026-11-05']);
    $b = planTask($this, ['name' => 'Footings', 'planned_start' => '2026-11-06', 'planned_finish' => '2026-11-12']);
    $unscheduled = planTask($this, ['name' => 'Snagging']);
    planLink($this, $b, $a);
    planLink($this, $unscheduled, $b);

    $this->actingInCompany($this->engineer, $this->company)
        ->getJson(route('projects.planning.gantt.data', $this->project))
        ->assertOk()
        ->assertJsonPath('unscheduled', 1)
        ->assertJsonCount(2, 'tasks')
        ->assertJsonPath('tasks.0.id', (string) $a->id)
        ->assertJsonPath('tasks.0.start', '2026-11-01')
        ->assertJsonPath('tasks.1.dependencies', [(string) $a->id])
        ->assertJsonPath('tasks.1.custom_class', 'gantt-status-not_started')
        ->assertJsonPath('tasks.1.name', '2 Footings');

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.planning.gantt', $this->project))
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Gantt')->has('dataUrl'));

    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->getJson(route('projects.planning.gantt.data', $this->project))->assertForbidden();
});

test('planning pages hide costs and actions the user may not use', function () {
    planTask($this, ['name' => 'Excavation', 'budget_amount' => '125000.50']);

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.planning.tasks.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Tasks')
            ->missing('tasks.0.budget_amount')
            ->missing('tasks.0.actual_cost')
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.update_progress', true)
            ->where('options.view_costs', false));

    $this->actingInCompany($this->pm, $this->company)
        ->get(route('projects.planning.tasks.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('tasks.0.budget_amount', '125000.50')->where('can.create', true));

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.planning.tasks.store', $this->project), ['name' => 'Nope'])
        ->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.progress', $this->project))
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Progress'));
});

test('planning data is isolated between companies', function () {
    $task = planTask($this, ['name' => 'Excavation']);
    $otherCompany = $this->createCompany();
    $outsider = $this->createMember($otherCompany, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($outsider, $otherCompany)->get(route('projects.planning.tasks.index', $this->project))->assertNotFound();
    $this->actingInCompany($outsider, $otherCompany)
        ->patch(route('projects.planning.tasks.status', [$this->project, $task]), ['status' => 'in_progress'])
        ->assertNotFound();
    expect($this->inCompany($this->company, fn () => $task->fresh()->status))->toBe(TaskStatus::NotStarted);
});
