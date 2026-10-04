<?php

use App\Enums\Planning\TaskStatus;
use App\Models\Core\NotificationPreference;
use App\Notifications\GeneralNotification;
use App\Services\Audit\AuditValueFormatter;
use App\Services\Planning\PlanningService;
use App\Support\Notifications\NotificationTypes;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProjectPlanningData;

uses(BuildsProjectPlanningData::class);

beforeEach(function () {
    $this->setUpProjectTeam();
});

test('assigning a task notifies the new assignee and a repeat save does not', function () {
    Notification::fake();

    $task = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, [
        'name' => 'Slab shuttering',
        'assigned_to' => $this->engineer->id,
    ]));

    Notification::assertSentTo($this->engineer, GeneralNotification::class, fn (GeneralNotification $n) => $n->kind === 'planning.task_assigned'
        && $n->context['project_id'] === $this->project->id);

    Notification::fake();
    $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, [
        'name' => 'Slab shuttering',
        'assigned_to' => $this->engineer->id,
    ], $task));

    Notification::assertNothingSent();
});

test('turning the in-app channel off stops that notification', function () {
    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('notifications.preferences'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Preferences')->has('futureChannels', 2));

    $this->actingInCompany($this->engineer, $this->company)
        ->put(route('notifications.preferences.update'), [
            'preferences' => [
                ['type' => 'planning.task_assigned', 'database' => false],
            ],
        ])->assertSessionHasNoErrors();

    expect(NotificationPreference::query()->where('user_id', $this->engineer->id)->where('notification_type', 'planning.task_assigned')->value('channels'))->toBe([]);

    Notification::fake();
    $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, [
        'name' => 'Steel binding',
        'assigned_to' => $this->engineer->id,
    ]));

    Notification::assertNothingSent();
});

test('overdue scan notifies the assignee and the project manager once per planned finish', function () {
    $task = $this->inCompany($this->company, function () {
        $saved = app(PlanningService::class)->saveTask($this->project, [
            'name' => 'Late plaster',
            'assigned_to' => $this->engineer->id,
            'planned_finish' => now()->subDays(2)->toDateString(),
        ]);
        $saved->forceFill(['status' => TaskStatus::InProgress])->save();

        return $saved;
    });

    Notification::fake();
    $this->artisan('planning:flag-delays')->assertSuccessful();
    Notification::assertSentTo($this->engineer, GeneralNotification::class, fn (GeneralNotification $n) => $n->kind === 'planning.task_overdue');
    Notification::assertSentTo($this->pm, GeneralNotification::class, fn (GeneralNotification $n) => $n->kind === 'planning.task_overdue');

    $this->inCompany($this->company, fn () => $task->fresh()->forceFill(['status' => TaskStatus::InProgress])->save());
    Notification::fake();
    $this->artisan('planning:flag-delays')->assertSuccessful();
    Notification::assertNothingSent();
});

test('the inbox filters by read state and type and mark all read clears the bell', function () {
    $this->engineer->notify(new GeneralNotification(
        $this->company->id, 'planning.task_assigned', 'Task assigned', 'Slab was assigned to you.',
        '/projects/1/planning/tasks', ['project_id' => $this->project->id],
    ));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('notifications.index', ['type' => 'planning.task_assigned', 'read' => 'unread']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('notifications.data', 1)
            ->where('notifications.data.0.url', '/projects/1/planning/tasks'));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('notifications.index', ['type' => 'quality.ncr_raised']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));

    $this->actingInCompany($this->engineer, $this->company)->post(route('notifications.read-all'))->assertRedirect();

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('notifications.index', ['read' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0)->where('unreadNotifications', 0));
});

test('the executive dashboard omits money for a user without financial permission', function () {
    $this->actingInCompany($this->engineer, $this->company)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('can.financials', false)
            ->has('dashboard.kpis.total_projects')
            ->missing('dashboard.kpis.budget')
            ->missing('dashboard.kpis.actual_cost')
            ->missing('dashboard.charts.cash_flow'));

    $this->actingInCompany($this->director, $this->company)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.financials', true)->has('dashboard.kpis.budget')->has('dashboard.kpis.vendor_payables'));
});

test('audit values are labelled and masked without changing the stored row', function () {
    $formatter = app(AuditValueFormatter::class);

    $this->inCompany($this->company, function () use ($formatter) {
        expect($formatter->display('pan', 'ABCDE1234F'))->toBe('••••234F')
            ->and($formatter->display('bank_account_no', '123456789012'))->toBe('••••9012')
            ->and($formatter->display('project_id', (string) $this->project->id))->toBe($this->project->code)
            ->and($formatter->display('name', 'Tower A'))->toBe('Tower A');
    });

    $task = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, ['name' => 'Audit me']));
    $raw = $task->auditLogs()->first();
    $before = $raw->new_values;

    $this->actingInCompany($this->engineer, $this->company)->get(route('admin.audit-logs.index'))->assertForbidden();

    $this->actingInCompany($this->admin, $this->company)
        ->get(route('admin.audit-logs.index', ['auditable_type' => 'project_task', 'record_id' => $task->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/AuditLogs/Index')->has('logs.data'));

    expect($raw->fresh()->new_values)->toBe($before)
        ->and(NotificationTypes::keys())->toContain('planning.task_overdue', 'finance.payment_received', 'quality.ncr_raised');
});

test('financial reports stay closed without reports.view_financial and the index respects reports.view', function () {
    $this->actingInCompany($this->engineer, $this->company)->get(route('reports.index'))->assertForbidden();

    $this->actingInCompany($this->pm, $this->company)->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Index'));

    $this->actingInCompany($this->pm, $this->company)->get(route('reports.show', 'budget-vs-actual'))->assertForbidden();

    $this->actingInCompany($this->director, $this->company)->get(route('reports.show', 'budget-vs-actual'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Show')->where('report.key', 'budget-vs-actual')->has('result.columns'));

    $this->actingInCompany($this->pm, $this->company)
        ->get(route('reports.export', ['report' => 'project-progress', 'format' => 'xlsx']))
        ->assertForbidden();
});

test('project overview hides contract value and financial cards without permission', function () {
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.contract_value', null)
            ->has('dashboard.operational')
            ->missing('dashboard.financial'));

    $this->actingInCompany($this->director, $this->company)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('dashboard.financial.budget')->where('project.contract_value', '0.00'));
});
