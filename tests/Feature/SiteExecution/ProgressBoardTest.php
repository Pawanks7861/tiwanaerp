<?php

use App\Enums\Planning\TaskStatus;
use App\Support\Planning\TaskDelay;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSiteExecutionData;

uses(BuildsSiteExecutionData::class);

beforeEach(function () {
    $this->setUpSiteExecution();
});

test('delay is computed from the planned finish, never stored', function () {
    $today = CarbonImmutable::parse('2026-10-10');
    $task = $this->makeTask(['planned_finish' => '2026-10-05', 'status' => TaskStatus::InProgress]);
    $done = $this->makeTask(['planned_finish' => '2026-10-05', 'status' => TaskStatus::Completed, 'actual_finish' => '2026-10-03']);
    $late = $this->makeTask(['planned_finish' => '2026-10-05', 'status' => TaskStatus::Completed, 'actual_finish' => '2026-10-08']);
    $open = $this->makeTask(['planned_finish' => null]);

    expect(TaskDelay::days($task, $today))->toBe(5)
        ->and(TaskDelay::days($done, $today))->toBe(-2)
        ->and(TaskDelay::days($late, $today))->toBe(3)
        ->and(TaskDelay::days($open, $today))->toBeNull()
        ->and(TaskDelay::days($task, CarbonImmutable::parse('2026-10-01')))->toBe(0);
});

test('planning:flag-delays marks overdue in-progress tasks delayed and leaves completed and on-hold tasks alone', function () {
    $overdue = $this->makeTask(['planned_finish' => now()->subDays(3)->toDateString(), 'status' => TaskStatus::InProgress]);
    $completed = $this->makeTask(['planned_finish' => now()->subDays(3)->toDateString(), 'status' => TaskStatus::Completed]);
    $onHold = $this->makeTask(['planned_finish' => now()->subDays(3)->toDateString(), 'status' => TaskStatus::OnHold]);
    $onTime = $this->makeTask(['planned_finish' => now()->addDays(3)->toDateString(), 'status' => TaskStatus::InProgress]);
    $fresh = fn ($t) => $this->inCompany($this->company, fn () => $t->fresh()->status);

    $this->artisan('planning:flag-delays', ['--dry-run' => true])->assertSuccessful();
    expect($fresh($overdue))->toBe(TaskStatus::InProgress);

    $this->artisan('planning:flag-delays')->assertSuccessful();
    expect($fresh($overdue))->toBe(TaskStatus::Delayed)
        ->and($fresh($completed))->toBe(TaskStatus::Completed)
        ->and($fresh($onHold))->toBe(TaskStatus::OnHold)
        ->and($fresh($onTime))->toBe(TaskStatus::InProgress);
});

test('progress on an overdue task makes it delayed, never overwriting on hold', function () {
    $late = $this->makeTask(['wbs_code' => '5.1', 'planned_qty' => '10', 'planned_finish' => now()->subDays(10)->toDateString()]);
    $held = $this->makeTask(['wbs_code' => '5.2', 'planned_qty' => '10', 'status' => TaskStatus::OnHold]);

    $this->postProgress('2', now()->subDays(2)->toDateString(), $late);
    $this->postProgress('3', now()->subDay()->toDateString(), $held);

    expect($this->inCompany($this->company, fn () => $late->fresh()))->status->toBe(TaskStatus::Delayed)->progress_percent->toBe('20.0000')
        ->and($this->inCompany($this->company, fn () => $held->fresh()))->status->toBe(TaskStatus::OnHold)->completed_qty->toBe('3.0000');
});

test('the progress board shows KPIs, per-unit totals and filters', function () {
    $bag = $this->makeTask(['wbs_code' => '2.1', 'name' => 'Plaster', 'planned_qty' => '50', 'unit_id' => $this->unitId('Bag')]);
    $this->makeTask(['wbs_code' => '3.1', 'name' => 'Overdue work', 'planned_qty' => '10', 'status' => TaskStatus::InProgress, 'planned_finish' => now()->subDays(4)->toDateString()]);
    $this->postProgress('25', now()->subDay()->toDateString());
    $this->postProgress('10', now()->subDays(2)->toDateString(), $bag);

    $client = fn () => $this->actingInCompany($this->engineer, $this->company);
    $client()->get(route('projects.progress', $this->project))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Planning/Progress')
            ->where('kpis.total', 3)
            ->where('kpis.leaf_tasks', 3)
            ->where('kpis.delayed', 1)
            ->where('kpis.overall_percent', '15.00')
            ->has('kpis.by_unit', 2)
            ->where('tasks.data.0.wbs_code', '1.1')
            ->where('tasks.data.0.progress_percent', '25.00')
            ->where('tasks.data.0.balance_qty', '75.0000'));

    $client()->get(route('projects.progress', $this->project).'?delayed=1')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.wbs_code', '3.1')->where('tasks.data.0.delay_days', 4));
    $client()->get(route('projects.progress', $this->project).'?wbs=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1)->where('tasks.data.0.name', 'Plaster'));
});
