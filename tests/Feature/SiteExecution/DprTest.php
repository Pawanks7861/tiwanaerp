<?php

use App\Enums\SiteExecution\DprStatus;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Inventory\StockTransaction;
use App\Models\Planning\ProgressEntry;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprItem;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\BoqRevisionService;
use App\Services\Planning\ProgressLedgerService;
use App\Services\SiteExecution\DprService;
use App\Services\SiteExecution\SiteDiaryService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsSiteExecutionData;

uses(BuildsSiteExecutionData::class);

beforeEach(function () {
    $this->setUpSiteExecution();
});

function dprOf($test, ?int $id = null): Dpr
{
    return $test->inCompany($test->company, fn () => $id ? Dpr::query()->findOrFail($id) : Dpr::query()->latest('id')->firstOrFail());
}

function dprItems($test, Dpr $dpr)
{
    return $test->inCompany($test->company, fn () => DprItem::query()->where('dpr_id', $dpr->id)->orderBy('sort_order')->get());
}

test('a DPR aggregates only the approved diaries of the date, numbered DPR-{PROJECT}-{Ymd}', function () {
    $cum = $this->unitId();
    $this->approveDiary($this->makeDiary([
        'weather' => 'Sunny',
        'work_items' => [['task_id' => $this->task->id, 'quantity' => '10', 'unit_id' => $cum], ['description' => 'Site cleaning', 'quantity' => '1', 'unit_id' => $cum]],
        'labours' => [['labour_trade_id' => $this->mason, 'headcount' => 6, 'hours' => '8']],
        'materials' => [['material_id' => $this->cement->id, 'quantity' => '30', 'unit_id' => $this->unitId('Bag')]],
        'issues' => 'Pump breakdown 1 h',
    ]));
    $this->approveDiary($this->makeDiary([
        'weather' => 'Cloudy',
        'work_items' => [['task_id' => $this->task->id, 'quantity' => '5', 'unit_id' => $cum]],
        'labours' => [['labour_trade_id' => $this->mason, 'headcount' => 4, 'hours' => '8']],
        'materials' => [['material_id' => $this->cement->id, 'quantity' => '12.5', 'unit_id' => $this->unitId('Bag')]],
    ]));
    $pending = $this->makeDiary(['work_items' => [['task_id' => $this->task->id, 'quantity' => '7', 'unit_id' => $cum]]]);
    $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->submit($pending, $this->engineer));
    $this->makeDiary(['work_items' => [['task_id' => $this->task->id, 'quantity' => '9', 'unit_id' => $cum]]]);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.dprs.store', $this->project), ['dpr_date' => now()->toDateString(), 'engineer_id' => $this->engineer->id, 'company_id' => 999, 'status' => 'approved'])
        ->assertSessionHasNoErrors();

    $dpr = dprOf($this);
    $items = dprItems($this, $dpr);
    [$labours, $materials] = $this->inCompany($this->company, fn () => [$dpr->labours()->get(), $dpr->materials()->get()]);

    expect($dpr->dpr_number)->toBe('DPR-'.$this->project->code.'-'.now()->format('Ymd'))
        ->and($dpr->status)->toBe(DprStatus::Draft)
        ->and($dpr->company_id)->toBe($this->company->id)
        ->and($dpr->weather)->toBe('Sunny; Cloudy')
        ->and($dpr->site_issues)->toContain('Pump breakdown 1 h')
        ->and($items)->toHaveCount(2)
        ->and($items[0]->task_id)->toBe($this->task->id)
        ->and($items[0]->executed_qty)->toBe('15.0000')
        ->and($items[0]->planned_qty)->toBe('100.0000')
        ->and($items[0]->cumulative_qty)->toBe('15.0000')
        ->and($items[0]->balance_qty)->toBe('85.0000')
        ->and($items[1]->description)->toBe('Site cleaning')
        ->and($labours)->toHaveCount(1)
        ->and($labours[0]->headcount)->toBe(10)
        ->and($materials)->toHaveCount(1)
        ->and($materials[0]->quantity)->toBe('42.5000')
        ->and(ProgressEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('one DPR per project and date; another project may use the same date; no approved diary means no DPR', function () {
    $this->approveDiary($this->makeDiary());
    $this->makeDpr();

    expect(fn () => $this->makeDpr())->toThrow(ValidationException::class);
    expect(fn () => $this->makeDpr(now()->subDay()->toDateString()))->toThrow(ValidationException::class);

    $otherTask = $this->makeTask(['wbs_code' => '1.1', 'planned_qty' => '50'], $this->otherProject);
    $this->approveDiary($this->makeDiary(['work_items' => [['task_id' => $otherTask->id, 'quantity' => '2', 'unit_id' => $this->unitId()]]], null, $this->otherProject));
    $other = $this->makeDpr(null, $this->otherProject);

    expect($other->dpr_number)->toBe('DPR-'.$this->otherProject->code.'-'.now()->format('Ymd'));
});

test('a draft DPR is editable; submitted and approved DPRs are locked', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->makeDpr();
    $item = dprItems($this, $dpr)->first();
    $client = fn () => $this->actingInCompany($this->engineer, $this->company);
    $payload = ['items' => [['id' => $item->id, 'task_id' => $this->task->id, 'unit_id' => $this->unitId(), 'executed_qty' => '12', 'planned_qty' => '1', 'cumulative_qty' => '999']]];

    $client()->put(route('projects.dprs.update', [$this->project, $dpr]), $payload)->assertSessionHasNoErrors();
    $item = dprItems($this, $dpr)->first();
    expect($item->executed_qty)->toBe('12.0000')->and($item->planned_qty)->toBe('100.0000')->and($item->cumulative_qty)->toBe('12.0000');

    $client()->post(route('projects.dprs.submit', [$this->project, $dpr]))->assertSessionHasNoErrors();
    expect(dprOf($this, $dpr->id)->status)->toBe(DprStatus::Submitted);
    $client()->put(route('projects.dprs.update', [$this->project, $dpr]), $payload)->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => $item->forceFill(['executed_qty' => '50'])->save()))->toThrow(ValidationException::class);

    $request = $this->inCompany($this->company, fn () => dprOf($this, $dpr->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();
    expect(dprOf($this, $dpr->id))->status->toBe(DprStatus::Approved)->approved_by->toBe($this->pm->id);
    $this->actingInCompany($this->pm, $this->company)->put(route('projects.dprs.update', [$this->project, $dpr]), $payload)->assertForbidden();
});

test('a rejected DPR posts nothing, becomes editable and can be resubmitted', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->makeDpr();
    $this->inCompany($this->company, fn () => app(DprService::class)->submit($dpr->fresh(), $this->engineer));

    $request = $this->inCompany($this->company, fn () => dprOf($this, $dpr->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.reject', $request->id), ['comments' => 'Quantities not measured'])->assertSessionHasNoErrors();

    expect(dprOf($this, $dpr->id)->status)->toBe(DprStatus::Rejected)
        ->and($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(0);

    $item = dprItems($this, $dpr)->first();
    $this->actingInCompany($this->engineer, $this->company)
        ->put(route('projects.dprs.update', [$this->project, $dpr]), ['items' => [['id' => $item->id, 'task_id' => $this->task->id, 'unit_id' => $this->unitId(), 'executed_qty' => '8']]])
        ->assertSessionHasNoErrors();
    $dpr = $this->approveDpr($dpr);

    expect($dpr->status)->toBe(DprStatus::Approved)->and($this->task()->completed_qty)->toBe('8.0000');
});

test('a DPR line cannot point at a task or BOQ line of another project', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->makeDpr();
    $foreignTask = $this->makeTask(['wbs_code' => '1.1', 'planned_qty' => '10'], $this->otherProject);
    $client = fn () => $this->actingInCompany($this->engineer, $this->company);

    $client()->put(route('projects.dprs.update', [$this->project, $dpr]), ['items' => [['task_id' => $foreignTask->id, 'unit_id' => $this->unitId(), 'executed_qty' => '1']]])
        ->assertSessionHasErrors('items.0.task_id');

    $this->inCompany($this->company, fn () => DprItem::query()->where('dpr_id', $dpr->id)->first()->forceFill(['task_id' => $foreignTask->id])->save());
    $client()->post(route('projects.dprs.submit', [$this->project, $dpr]))->assertSessionHasErrors('items');
    expect($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(0);
});

test('DPR approval is the only writer of progress: entries, task cache, percent and actual dates', function () {
    $yesterday = now()->subDay()->toDateString();
    $first = $this->postProgress('25', $yesterday);

    $entry = $this->inCompany($this->company, fn () => ProgressEntry::query()->sole());
    $task = $this->task();
    expect($entry->quantity)->toBe('25.0000')
        ->and($entry->task_id)->toBe($this->task->id)
        ->and($entry->entry_date->toDateString())->toBe($yesterday)
        ->and($entry->source_type)->toBe('dpr_item')
        ->and($entry->posting_ref)->toBe("dpr:{$first->id}:r0:item:".dprItems($this, $first)->first()->id)
        ->and($entry->created_by)->toBe($this->pm->id)
        ->and($task->completed_qty)->toBe('25.0000')
        ->and($task->progress_percent)->toBe('25.0000')
        ->and($task->status->value)->toBe('in_progress')
        ->and($task->actual_start->toDateString())->toBe($yesterday)
        ->and($task->actual_finish)->toBeNull();

    $this->postProgress('75');
    $task = $this->task();
    expect($task->completed_qty)->toBe('100.0000')
        ->and($task->progress_percent)->toBe('100.0000')
        ->and($task->status->value)->toBe('completed')
        ->and($task->actual_start->toDateString())->toBe($yesterday)
        ->and($task->actual_finish->toDateString())->toBe(now()->toDateString());
});

test('hand check: planned 100, DPR 25 → 25 %, DPR 35 → 60 %', function () {
    $this->postProgress('25', now()->subDays(2)->toDateString());
    expect($this->task())->completed_qty->toBe('25.0000')->progress_percent->toBe('25.0000');

    $this->postProgress('35', now()->subDay()->toDateString());
    expect($this->task())->completed_qty->toBe('60.0000')->progress_percent->toBe('60.0000');
});

test('posting is idempotent: re-running the approval or the ledger posting adds nothing', function () {
    $dpr = $this->postProgress('10');

    $this->inCompany($this->company, function () use ($dpr) {
        app(DprService::class)->post($dpr->fresh(), $this->pm->id);
        app(ProgressLedgerService::class)->postDpr($dpr->fresh(), $this->pm->id);
    });

    expect($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(1)
        ->and($this->task()->completed_qty)->toBe('10.0000');
});

test('over-progress is refused at submit and again at approval', function () {
    $this->postProgress('90', now()->subDays(2)->toDateString());

    $this->approveDiary($this->makeDiary(['diary_date' => now()->subDay()->toDateString(), 'work_items' => [['task_id' => $this->task->id, 'quantity' => '20', 'unit_id' => $this->unitId()]]]));
    $dpr = $this->makeDpr(now()->subDay()->toDateString());
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.dprs.submit', [$this->project, $dpr]))->assertSessionHasErrors('items');
    expect(dprOf($this, $dpr->id)->status)->toBe(DprStatus::Draft);

    $a = $this->makeTask(['wbs_code' => '3.1', 'planned_qty' => '10']);
    $this->approveDiary($this->makeDiary(['diary_date' => now()->subDays(4)->toDateString(), 'work_items' => [['task_id' => $a->id, 'quantity' => '6', 'unit_id' => $a->unit_id]]]));
    $this->approveDiary($this->makeDiary(['diary_date' => now()->subDays(3)->toDateString(), 'work_items' => [['task_id' => $a->id, 'quantity' => '6', 'unit_id' => $a->unit_id]]]));
    $d1 = $this->makeDpr(now()->subDays(4)->toDateString());
    $d2 = $this->makeDpr(now()->subDays(3)->toDateString());
    $this->inCompany($this->company, function () use ($d1, $d2) {
        app(DprService::class)->submit($d1->fresh(), $this->engineer);
        app(DprService::class)->submit($d2->fresh(), $this->engineer);
        app(ApprovalService::class)->approve($d1->fresh()->pendingApprovalRequest(), $this->pm);
    });

    $request = $this->inCompany($this->company, fn () => dprOf($this, $d2->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasErrors();

    expect(dprOf($this, $d2->id)->status)->toBe(DprStatus::Submitted)
        ->and($this->inCompany($this->company, fn () => $a->fresh()->completed_qty))->toBe('6.0000');
});

test('reopening an approved DPR reverses its entries; the corrected revision posts again', function () {
    $dpr = $this->postProgress('100');
    expect($this->task()->status->value)->toBe('completed');

    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.dprs.reopen', [$this->project, $dpr]), ['reason' => 'Wrong quantity'])->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.dprs.reopen', [$this->project, $dpr]), ['reason' => 'Wrong quantity'])->assertSessionHasNoErrors();

    $dpr = dprOf($this, $dpr->id);
    $entries = $this->inCompany($this->company, fn () => ProgressEntry::query()->orderBy('id')->get());
    $task = $this->task();
    expect($dpr->status)->toBe(DprStatus::Draft)
        ->and($dpr->revision)->toBe(1)
        ->and($dpr->reopen_reason)->toBe('Wrong quantity')
        ->and($entries)->toHaveCount(2)
        ->and($entries[1]->quantity)->toBe('-100.0000')
        ->and($entries[1]->reverses_id)->toBe($entries[0]->id)
        ->and($entries[1]->entry_date->toDateString())->toBe($entries[0]->entry_date->toDateString())
        ->and($task->completed_qty)->toBe('0.0000')
        ->and($task->status->value)->toBe('in_progress')
        ->and($task->actual_finish)->toBeNull()
        ->and($task->actual_start)->not->toBeNull();

    $item = dprItems($this, $dpr)->first();
    $client = fn () => $this->actingInCompany($this->engineer, $this->company);
    $client()->put(route('projects.dprs.update', [$this->project, $dpr]), ['items' => []])->assertSessionHasErrors('items');
    $client()->delete(route('projects.dprs.destroy', [$this->project, $dpr]))->assertForbidden();
    $client()->put(route('projects.dprs.update', [$this->project, $dpr]), ['items' => [['id' => $item->id, 'task_id' => $this->task->id, 'unit_id' => $this->unitId(), 'executed_qty' => '40']]])->assertSessionHasNoErrors();

    $this->approveDpr($dpr);
    $refs = $this->inCompany($this->company, fn () => ProgressEntry::query()->orderBy('id')->pluck('posting_ref')->all());
    expect($refs[2])->toBe("dpr:{$dpr->id}:r1:item:{$item->id}")
        ->and($this->task())->completed_qty->toBe('40.0000')->progress_percent->toBe('40.0000');
    expect(fn () => $this->inCompany($this->company, fn () => ProgressEntry::query()->first()->forceFill(['quantity' => '1'])->save()))->toThrow(LogicException::class)
        ->and(fn () => $this->inCompany($this->company, fn () => ProgressEntry::query()->first()->delete()))->toThrow(LogicException::class);
});

test('BOQ progress follows line_uid across BOQ revisions and refuses BOQ over-progress', function () {
    $line = $this->approvedBoqLine();
    $task = $this->makeTask(['wbs_code' => '4.1', 'boq_item_id' => $line->id, 'planned_qty' => '0']);
    $this->postProgress('5', now()->subDays(2)->toDateString(), $task);

    $entry = $this->inCompany($this->company, fn () => ProgressEntry::query()->sole());
    expect($entry->boq_item_id)->toBe($line->id)->and($entry->boq_line_uid)->toBe($line->line_uid);

    $v2 = $this->inCompany($this->company, fn () => app(BoqRevisionService::class)->revise($line->boq));
    $this->approveBoq($v2);
    $newLine = $this->inCompany($this->company, fn () => $v2->items()->where('line_uid', $line->line_uid)->sole());
    expect($this->inCompany($this->company, fn () => $task->fresh()->boq_item_id))->toBe($newLine->id);

    $draft = $this->makeDiary(['work_location' => 'saved before the revision']);
    $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->update($draft, [
        'diary_date' => now()->toDateString(),
        'work_items' => [['task_id' => $task->id, 'boq_item_id' => $line->id, 'quantity' => '1', 'unit_id' => $task->unit_id]],
    ]));
    expect($this->inCompany($this->company, fn () => $draft->workItems()->sole()->boq_item_id))->toBe($newLine->id);
    $this->inCompany($this->company, fn () => app(SiteDiaryService::class)->delete($draft->fresh()));

    $this->postProgress('5', now()->subDay()->toDateString(), $task->fresh());
    $executed = $this->inCompany($this->company, fn () => app(ProgressLedgerService::class)->executedByLine($this->project));
    expect($executed[$line->line_uid])->toBe('10.0000');

    $this->actingInCompany($this->pm, $this->company)->get(route('projects.progress.boq', $this->project))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Planning/BoqProgress')
            ->where('boqs.0.lines.0.executed', '10.0000')
            ->where('boqs.0.lines.0.balance', '2.5000')
            ->where('boqs.0.lines.0.percent', '80.00'));

    $this->approveDiary($this->makeDiary(['work_items' => [['task_id' => $task->id, 'quantity' => '3', 'unit_id' => $task->unit_id]]]));
    expect(fn () => $this->approveDpr($this->makeDpr()))->toThrow(ValidationException::class);
});

test('only approvers holding dpr.approve can post a DPR', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->makeDpr();
    $this->inCompany($this->company, fn () => app(DprService::class)->submit($dpr->fresh(), $this->engineer));

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    Role::query()->where('name', DefaultRoles::PROJECT_MANAGER)->where('team_id', $this->company->id)->firstOrFail()->revokePermissionTo('dpr.approve');
    $registrar->forgetCachedPermissions();

    expect(fn () => $this->inCompany($this->company, fn () => app(ApprovalService::class)->approve(dprOf($this, $dpr->id)->pendingApprovalRequest(), $this->pm)))->toThrow(ValidationException::class);
    expect(dprOf($this, $dpr->id)->status)->toBe(DprStatus::Submitted)
        ->and($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(0);
});

test('material usage in diaries and DPRs never posts stock or cost', function () {
    $this->approveDiary($this->makeDiary(['materials' => [['material_id' => $this->cement->id, 'quantity' => '25', 'unit_id' => $this->unitId('Bag')]]]));
    $dpr = $this->approveDpr($this->makeDpr());

    expect($dpr->status)->toBe(DprStatus::Approved)
        ->and($this->inCompany($this->company, fn () => $dpr->materials()->sole()->quantity))->toBe('25.0000')
        ->and($this->inCompany($this->company, fn () => StockTransaction::query()->count()))->toBe(0)
        ->and($this->inCompany($this->company, fn () => ProjectCostEntry::query()->count()))->toBe(0);
});

test('unlinked work lines are reported but post no progress', function () {
    $this->approveDiary($this->makeDiary(['work_items' => [['description' => 'Housekeeping', 'quantity' => '1', 'unit_id' => $this->unitId()]]]));
    $this->approveDpr($this->makeDpr());

    expect($this->inCompany($this->company, fn () => ProgressEntry::query()->count()))->toBe(0);
});

test('a deleted draft DPR is restored with its number when the date is created again', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->makeDpr();

    $this->actingInCompany($this->engineer, $this->company)->delete(route('projects.dprs.destroy', [$this->project, $dpr]))->assertSessionHasNoErrors();
    $again = $this->makeDpr();

    expect($again->id)->toBe($dpr->id)->and($again->dpr_number)->toBe($dpr->dpr_number)->and($again->trashed())->toBeFalse();
});

test('DPR screens, PDF export permission and isolation', function () {
    $this->approveDiary($this->makeDiary());
    $dpr = $this->approveDpr($this->makeDpr());
    $engineer = fn () => $this->actingInCompany($this->engineer, $this->company);

    $engineer()->get(route('projects.dprs.index', $this->project))->assertOk()->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Dprs/Index')->has('dprs.data', 1));
    $engineer()->get(route('projects.dprs.show', [$this->project, $dpr]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Dprs/Show')->has('entries', 1)->where('can.export', false)->where('can.reopen', false));
    $engineer()->get(route('projects.dprs.create', $this->project).'?date='.now()->toDateString())->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SiteExecution/Dprs/Create')->where('existing.id', $dpr->id));
    $engineer()->get(route('projects.dprs.pdf', [$this->project, $dpr]))->assertForbidden();

    $response = $this->actingInCompany($this->pm, $this->company)->get(route('projects.dprs.pdf', [$this->project, $dpr]));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('pdf');

    $this->actingInCompany($this->pm, $this->company)->get(route('projects.dprs.show', [$this->otherProject, $dpr]))->assertNotFound();
    $outsiderCompany = $this->createCompany();
    $outsider = $this->createMember($outsiderCompany, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $outsiderCompany)->get(route('projects.dprs.show', [$this->project, $dpr]))->assertNotFound();
});
