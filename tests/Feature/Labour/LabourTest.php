<?php

use App\Enums\CostHead;
use App\Enums\Labour\AttendanceApproval;
use App\Enums\Labour\AttendanceStatus;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAttendance;
use App\Models\Masters\LabourTrade;
use App\Models\Projects\Site;
use App\Services\Finance\ProjectCostLedgerService;
use App\Services\Labour\LabourAttendanceService;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

beforeEach(function () {
    $this->setUpResources();
});

function sheet($test, array $rows, array $extra = []): array
{
    return $extra + ['attendance_date' => now()->toDateString(), 'site_id' => $test->site->id, 'rows' => $rows];
}

test('labour register CRUD numbers labourers, defaults the wage from the trade and masks the ID number for viewers', function () {
    $admin = $this->actingInCompany($this->admin, $this->company);
    $admin->post(route('masters.store', 'labour'), [
        'name' => 'Mahesh Jadhav', 'labour_trade_id' => $this->mason, 'current_project_id' => $this->project->id,
        'id_proof_type' => 'aadhaar', 'id_proof_no' => '123456789012', 'daily_wage' => '750', 'ot_rate_per_hour' => '95', 'company_id' => 999,
    ])->assertSessionHasNoErrors();

    $labour = $this->inCompany($this->company, fn () => Labour::query()->where('name', 'Mahesh Jadhav')->sole());
    expect($labour->code)->toBe('LAB-0001')
        ->and($labour->company_id)->toBe($this->company->id)
        ->and($labour->daily_wage)->toBe('750.00')
        ->and($labour->ot_rate_per_hour)->toBe('95.0000');

    $admin->put(route('masters.update', ['labour', $labour->id]), [
        'code' => 'LAB-0001', 'name' => 'Mahesh J.', 'labour_trade_id' => $this->mason, 'daily_wage' => '780', 'is_active' => true,
    ])->assertSessionHasNoErrors();
    expect($labour->fresh()->daily_wage)->toBe('780.00');

    $this->actingInCompany($this->accountant, $this->company)->get(route('masters.index', 'labour'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.data', fn ($rows) => collect($rows)->firstWhere('name', 'Mahesh J.')['id_proof_no'] === '••••••••9012'));
    $this->actingInCompany($this->accountant, $this->company)->post(route('masters.store', 'labour'), ['name' => 'X', 'labour_trade_id' => $this->mason])->assertForbidden();

    $admin = $this->actingInCompany($this->admin, $this->company);
    $admin->delete(route('masters.destroy', ['labour', $labour->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Labour::query()->whereKey($labour->id)->exists()))->toBeFalse();

    // A labourer with attendance cannot be deleted.
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present']]);
    $admin->delete(route('masters.destroy', ['labour', $this->ravi->id]))->assertSessionHasErrors('record');
    expect($this->inCompany($this->company, fn () => Labour::query()->whereKey($this->ravi->id)->exists()))->toBeTrue();
});

test('bulk attendance snapshots wages: present, half day, absent, leave and OT, with hours derived on the server', function () {
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.attendance.store', $this->project), sheet($this, [
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2', 'task_id' => $this->task->id],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day', 'wage_amount' => '99999'],
    ]))->assertSessionHasNoErrors();

    $ravi = $this->attendanceOf($this->ravi);
    $sunil = $this->attendanceOf($this->sunil);
    expect($ravi->status)->toBe(AttendanceStatus::Present)
        ->and($ravi->approval_status)->toBe(AttendanceApproval::Marked)
        ->and($ravi->working_hours)->toBe('8.00')
        ->and($ravi->daily_wage)->toBe('800.00')
        ->and($ravi->ot_rate)->toBe('120.0000')
        ->and($ravi->wage_amount)->toBe('800.00')
        ->and($ravi->ot_amount)->toBe('240.00')
        ->and($ravi->site_id)->toBe($this->site->id)
        ->and($ravi->marked_by)->toBe($this->engineer->id)
        ->and($sunil->working_hours)->toBe('4.00')
        ->and($sunil->wage_amount)->toBe('300.00')
        ->and($sunil->ot_amount)->toBe('0.00');

    // Re-marking edits the same row (unique per labourer per date) and re-snapshots.
    $this->mark([
        ['labour_id' => $this->ravi->id, 'status' => 'absent', 'ot_hours' => '3'],
        ['labour_id' => $this->sunil->id, 'status' => 'leave'],
    ]);
    expect($this->inCompany($this->company, fn () => LabourAttendance::query()->count()))->toBe(2)
        ->and($this->attendanceOf($this->ravi)->wage_amount)->toBe('0.00')
        ->and($this->attendanceOf($this->ravi)->ot_amount)->toBe('0.00')
        ->and($this->attendanceOf($this->sunil)->wage_amount)->toBe('0.00');

    // Wage changes in the register never alter a marked snapshot.
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present']]);
    $this->inCompany($this->company, fn () => $this->ravi->update(['daily_wage' => '900']));
    expect($this->attendanceOf($this->ravi)->wage_amount)->toBe('800.00');
});

test('punch times give hours, overnight shifts wrap safely and invalid punches are rejected', function () {
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present', 'punch_in' => '22:00', 'punch_out' => '06:30', 'ot_hours' => '0.5']]);
    expect($this->attendanceOf($this->ravi)->working_hours)->toBe('8.50')
        ->and($this->attendanceOf($this->ravi)->ot_amount)->toBe('60.00');

    $fails = function (array $row, string $key) {
        try {
            $this->mark([$row]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($key);
        }
    };
    $fails(['labour_id' => $this->sunil->id, 'status' => 'present', 'punch_in' => '09:00', 'punch_out' => '09:00'], 'rows.0.punch_out');
    $fails(['labour_id' => $this->sunil->id, 'status' => 'present', 'punch_in' => '09:00'], 'rows.0.punch_out');
    $fails(['labour_id' => $this->sunil->id, 'status' => 'present', 'punch_in' => '25:00', 'punch_out' => '09:00'], 'rows.0.punch_in');
    $fails(['labour_id' => $this->sunil->id, 'status' => 'present', 'punch_in' => '09:00', 'punch_out' => '11:00', 'ot_hours' => '3'], 'rows.0.ot_hours');
    $fails(['labour_id' => $this->sunil->id, 'status' => 'present', 'ot_hours' => '-1'], 'rows.0.ot_hours');
});

test('duplicates are blocked: twice on one sheet, on another project the same day, future dates and foreign sites', function () {
    $engineer = $this->actingInCompany($this->engineer, $this->company);
    $engineer->post(route('projects.attendance.store', $this->project), sheet($this, [
        ['labour_id' => $this->ravi->id, 'status' => 'present'],
        ['labour_id' => $this->ravi->id, 'status' => 'half_day'],
    ]))->assertSessionHasErrors('rows.1.labour_id');

    $this->inCompany($this->company, fn () => app(LabourAttendanceService::class)->mark($this->otherProject, [
        'attendance_date' => now()->toDateString(), 'rows' => [['labour_id' => $this->outsider->id, 'status' => 'present']],
    ], $this->engineer));
    $engineer->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $this->outsider->id, 'status' => 'present']]))
        ->assertSessionHasErrors('rows.0.labour_id');

    $engineer->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $this->ravi->id, 'status' => 'present']], ['attendance_date' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors('attendance_date');

    $foreignSite = $this->inCompany($this->company, function () {
        $site = new Site(['name' => 'Tower B yard', 'is_active' => true]);
        $site->forceFill(['project_id' => $this->otherProject->id])->save();

        return $site;
    });
    $engineer->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $this->ravi->id, 'status' => 'present']], ['site_id' => $foreignSite->id]))
        ->assertSessionHasErrors('site_id');

    expect($this->inCompany($this->company, fn () => LabourAttendance::query()->where('project_id', $this->project->id)->count()))->toBe(0);
});

test('approval posts one labour cost per day with BOQ line and task; retries and re-approvals never double it', function () {
    $this->mark([
        ['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2', 'task_id' => $this->task->id],
        ['labour_id' => $this->sunil->id, 'status' => 'half_day'],
    ]);
    $ids = $this->inCompany($this->company, fn () => LabourAttendance::query()->pluck('id')->all());

    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.attendance.approve', $this->project), ['ids' => $ids])->assertForbidden();
    $pm = $this->actingInCompany($this->pm, $this->company);
    $pm->post(route('projects.attendance.approve', $this->project), ['ids' => $ids])->assertSessionHasNoErrors();
    $pm->post(route('projects.attendance.approve', $this->project), ['ids' => $ids])->assertSessionHasNoErrors();

    $ravi = $this->attendanceOf($this->ravi);
    $costs = $this->costs(CostHead::Labour);
    $raviCost = $costs->firstWhere('source_id', $ravi->id);
    expect($ravi->approval_status)->toBe(AttendanceApproval::Approved)
        ->and($ravi->approved_by)->toBe($this->pm->id)
        ->and($costs)->toHaveCount(2)
        ->and($raviCost->amount)->toBe('1040.00')
        ->and($raviCost->source_type)->toBe('labour_attendance')
        ->and($raviCost->boq_item_id)->toBe($this->boqLine->id)
        ->and($raviCost->boq_line_uid)->toBe($this->boqLine->line_uid)
        ->and($raviCost->task_id)->toBe($this->task->id)
        ->and($raviCost->entry_date->toDateString())->toBe(now()->toDateString())
        ->and($costs->firstWhere('source_id', $this->attendanceOf($this->sunil)->id)->amount)->toBe('300.00')
        ->and($this->netCost(CostHead::Labour))->toBe('1340.00');

    // Direct retry of the posting service is idempotent.
    $again = $this->inCompany($this->company, fn () => app(ProjectCostLedgerService::class)->post(
        source: $ravi, projectId: $this->project->id, head: CostHead::Labour, amount: Decimal::of('1040.00'), date: now()->toDateString(),
    ));
    expect($again->id)->toBe($raviCost->id)->and($this->costs(CostHead::Labour))->toHaveCount(2);

    // Approved rows are frozen.
    expect(fn () => $this->mark([['labour_id' => $this->ravi->id, 'status' => 'absent']]))->toThrow(ValidationException::class);
    $pm->delete(route('projects.attendance.destroy', [$this->project, $ravi]))->assertForbidden();

    // Un-approve reverses; editing and approving again re-posts exactly once.
    $pm->post(route('projects.attendance.unapprove', [$this->project, $ravi]), ['reason' => 'Wrong OT hours'])->assertSessionHasNoErrors();
    expect($this->netCost(CostHead::Labour))->toBe('300.00');
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '1', 'task_id' => $this->task->id]]);
    $pm->post(route('projects.attendance.approve', $this->project), ['ids' => [$ravi->id]])->assertSessionHasNoErrors();
    $pm->post(route('projects.attendance.approve', $this->project), ['ids' => [$ravi->id]])->assertSessionHasNoErrors();

    $raviRows = $this->inCompany($this->company, fn () => ProjectCostEntry::query()->where('source_type', 'labour_attendance')->where('source_id', $ravi->id)->orderBy('id')->get());
    expect($raviRows->pluck('amount')->all())->toBe(['1040.00', '-1040.00', '920.00'])
        ->and($raviRows->pluck('posting_ref')->all())->toBe(['', '', 'r1'])
        ->and($this->netCost(CostHead::Labour))->toBe('1220.00');
});

test('absent and leave days post no cost; zero-amount approvals are recorded without ledger rows', function () {
    $this->approvedDay([
        ['labour_id' => $this->ravi->id, 'status' => 'absent'],
        ['labour_id' => $this->sunil->id, 'status' => 'leave'],
    ]);

    expect($this->inCompany($this->company, fn () => LabourAttendance::query()->where('approval_status', 'approved')->count()))->toBe(2)
        ->and($this->costs(CostHead::Labour))->toHaveCount(0);
});

test('attendance is isolated by tenant and project, and needs the right permission', function () {
    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $other)->get(route('projects.attendance.index', $this->project))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $this->ravi->id, 'status' => 'present']]))->assertNotFound();

    // A labourer of another company cannot be marked.
    $foreign = $this->inCompany($other, fn () => Labour::query()->create(['code' => 'F1', 'name' => 'Foreign', 'labour_trade_id' => LabourTrade::query()->value('id'), 'daily_wage' => '500', 'ot_rate_per_hour' => '0']));
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $foreign->id, 'status' => 'present']]))
        ->assertSessionHasErrors('rows.0.labour_id');

    // Accountant views but cannot mark; a non-member engineer cannot even view.
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.attendance.index', $this->project))->assertOk();
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.attendance.store', $this->project), sheet($this, [['labour_id' => $this->ravi->id, 'status' => 'present']]))->assertForbidden();
    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->get(route('projects.attendance.index', $this->project))->assertForbidden();

    // Attendance of another project cannot be reached through this project's URL.
    $this->inCompany($this->company, fn () => app(LabourAttendanceService::class)->mark($this->otherProject, [
        'attendance_date' => now()->toDateString(), 'rows' => [['labour_id' => $this->outsider->id, 'status' => 'present']],
    ], $this->engineer));
    $row = $this->attendanceOf($this->outsider);
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.attendance.destroy', [$this->project, $row]))->assertNotFound();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.attendance.approve', $this->project), ['ids' => [$row->id]]);
    expect($row->fresh()->approval_status)->toBe(AttendanceApproval::Marked);
});

test('the attendance sheet lists the crew with marked state and summary', function () {
    $this->mark([['labour_id' => $this->ravi->id, 'status' => 'present', 'ot_hours' => '2']]);

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.attendance.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Labour/Attendance')
            ->has('rows', 2)
            ->where('summary.present', 1)
            ->where('summary.marked', 1)
            ->where('summary.wages', '1040.00')
            ->where('can.mark', true)
            ->where('can.approve', false));

    $this->actingInCompany($this->pm, $this->company)->get(route('projects.labour.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Labour/Crew')->has('crew', 2));
});
