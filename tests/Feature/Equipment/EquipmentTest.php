<?php

use App\Enums\CostHead;
use App\Enums\Equipment\AssignmentStatus;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\RepairStatus;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\Vendor;
use App\Models\Projects\Site;
use App\Services\Equipment\EquipmentAssignmentService;
use App\Services\Equipment\EquipmentUsageService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

beforeEach(function () {
    $this->setUpResources();
});

function usageLog($test, EquipmentAssignment $assignment, array $data): EquipmentUsageLog
{
    return $test->inCompany($test->company, fn () => app(EquipmentUsageService::class)->create($test->project, $data + [
        'equipment_assignment_id' => $assignment->id,
        'log_date' => now()->toDateString(),
    ]));
}

test('equipment register CRUD: owned and hired, the owner vendor is required for hired equipment', function () {
    $store = route('masters.store', 'equipment');
    $as = fn () => $this->actingInCompany($this->admin, $this->company);

    $as()->post($store, ['name' => 'Roller', 'equipment_type_id' => $this->mixer, 'ownership' => 'hired', 'daily_rate' => '3000'])->assertSessionHasErrors('owner_vendor_id');
    $other = $this->createCompany();
    $foreignVendor = $this->inCompany($other, fn () => Vendor::query()->create(['code' => 'FV', 'name' => 'Foreign vendor', 'state_code' => '27']));
    $as()->post($store, ['name' => 'Roller', 'equipment_type_id' => $this->mixer, 'ownership' => 'hired', 'owner_vendor_id' => $foreignVendor->id])->assertSessionHasErrors('owner_vendor_id');

    $as()->post($store, ['name' => 'Roller', 'equipment_type_id' => $this->mixer, 'ownership' => 'hired', 'owner_vendor_id' => $this->hireVendor->id,
        'daily_rate' => '3000', 'purchase_value' => '999', 'company_id' => 999])->assertRedirect()->assertSessionHasNoErrors();
    $roller = $this->inCompany($this->company, fn () => Equipment::query()->where('name', 'Roller')->sole());
    expect($roller->code)->toStartWith('EQP-')
        ->and($roller->company_id)->toBe($this->company->id)
        ->and($roller->owner_vendor_id)->toBe($this->hireVendor->id)
        ->and($roller->purchase_value)->toBeNull()
        ->and($roller->daily_rate)->toBe('3000.0000')
        ->and($roller->hourly_rate)->toBe('0.0000')
        ->and($roller->status)->toBe(EquipmentStatus::Available);

    $as()->post($store, ['name' => 'Vibrator', 'equipment_type_id' => $this->mixer, 'ownership' => 'owned', 'owner_vendor_id' => $this->hireVendor->id, 'purchase_value' => '45000'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $vibrator = $this->inCompany($this->company, fn () => Equipment::query()->where('name', 'Vibrator')->sole());
    expect($vibrator->owner_vendor_id)->toBeNull()->and($vibrator->purchase_value)->toBe('45000.00');

    // Assigned equipment cannot be disposed from the register, nor set to "assigned" by hand.
    $this->issueEquipment($roller, 'daily');
    $as()->put(route('masters.update', ['equipment', $roller->id]), ['code' => $roller->code, 'name' => 'Roller', 'equipment_type_id' => $this->mixer,
        'ownership' => 'hired', 'owner_vendor_id' => $this->hireVendor->id, 'status' => 'disposed', 'is_active' => true])->assertSessionHasErrors('status');
    $as()->put(route('masters.update', ['equipment', $vibrator->id]), ['code' => $vibrator->code, 'name' => 'Vibrator', 'equipment_type_id' => $this->mixer,
        'ownership' => 'owned', 'status' => 'assigned', 'is_active' => true])->assertSessionHasErrors('status');

    // Viewers cannot create; unused equipment can be deleted, used equipment cannot.
    $this->actingInCompany($this->accountant, $this->company)->post($store, ['name' => 'X', 'equipment_type_id' => $this->mixer, 'ownership' => 'owned'])->assertForbidden();
    $as()->delete(route('masters.destroy', ['equipment', $vibrator->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => Equipment::query()->whereKey($vibrator->id)->exists()))->toBeFalse();
    $as()->delete(route('masters.destroy', ['equipment', $roller->id]))->assertSessionHasErrors('record');
});

test('assignment: issue defaults the rate, a second active assignment is blocked, return frees the equipment', function () {
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $this->excavator->id, 'issue_date' => now()->subDays(3)->toDateString(), 'rate_basis' => 'hourly',
        'site_id' => $this->site->id, 'task_id' => $this->task->id, 'operator_name' => 'Ramesh',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $assignment = $this->inCompany($this->company, fn () => EquipmentAssignment::query()->sole());
    expect($assignment->rate)->toBe('1500.0000')
        ->and($assignment->status)->toBe(AssignmentStatus::Active)
        ->and($assignment->site_id)->toBe($this->site->id)
        ->and($this->excavator->fresh()->status)->toBe(EquipmentStatus::Assigned);

    // Already assigned: neither this project nor another can take it.
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $this->excavator->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'hourly',
    ])->assertSessionHasErrors('equipment_id');
    expect(fn () => $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->issue($this->otherProject, [
        'equipment_id' => $this->excavator->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'hourly',
    ])))->toThrow(ValidationException::class);

    // A site of another project and a future date are rejected; the engineer cannot issue.
    $foreignSite = $this->inCompany($this->company, function () {
        $site = new Site(['name' => 'Tower B site', 'is_active' => true]);
        $site->forceFill(['project_id' => $this->otherProject->id])->save();

        return $site;
    });
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $this->hiredMixer->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'daily', 'site_id' => $foreignSite->id,
    ])->assertSessionHasErrors('site_id');
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $this->hiredMixer->id, 'issue_date' => now()->addDay()->toDateString(), 'rate_basis' => 'daily',
    ])->assertSessionHasErrors('issue_date');
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $this->hiredMixer->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'daily',
    ])->assertForbidden();

    // An explicit rate overrides the register; the hired mixer is billed daily.
    $mixer = $this->issueEquipment($this->hiredMixer, 'daily', ['rate' => '1800']);
    expect($mixer->rate)->toBe('1800.0000');

    // Return: not before the issue date, not before the last usage log.
    usageLog($this, $assignment, ['log_date' => now()->subDay()->toDateString(), 'working_hours' => '4']);
    $return = fn (string $date) => $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.return', [$this->project, $assignment]), ['return_date' => $date]);
    $return(now()->subDays(4)->toDateString())->assertSessionHasErrors('return_date');
    $return(now()->subDays(2)->toDateString())->assertSessionHasErrors('return_date');
    $return(now()->toDateString())->assertRedirect()->assertSessionHasNoErrors();

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Returned)
        ->and($assignment->returned_by)->toBe($this->pm->id)
        ->and($this->excavator->fresh()->status)->toBe(EquipmentStatus::Available);
    $return(now()->toDateString())->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->put(route('projects.equipment-assignments.update', [$this->project, $assignment]), ['operator_name' => 'X'])->assertForbidden();

    // Available again: it can now go to Tower B.
    $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->issue($this->otherProject, [
        'equipment_id' => $this->excavator->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'hourly',
    ]));
    expect($this->excavator->fresh()->status)->toBe(EquipmentStatus::Assigned);
});

test('usage logs: meters derive working hours, validations, and posting writes equipment cost exactly once', function () {
    $assignment = $this->issueEquipment($this->excavator);
    $store = route('projects.equipment-usage.store', $this->project);
    $as = fn () => $this->actingInCompany($this->engineer, $this->company);
    $base = ['equipment_assignment_id' => $assignment->id, 'log_date' => now()->subDay()->toDateString()];

    $as()->post($store, $base + ['opening_meter' => '1208', 'closing_meter' => '1200'])->assertSessionHasErrors('closing_meter');
    $as()->post($store, $base + ['opening_meter' => '1200'])->assertSessionHasErrors('closing_meter');
    $as()->post($store, $base + ['working_hours' => '20', 'idle_hours' => '5'])->assertSessionHasErrors('working_hours');
    $as()->post($store, ['log_date' => now()->subDays(4)->toDateString()] + $base)->assertSessionHasErrors('log_date');
    $as()->post($store, ['log_date' => now()->addDay()->toDateString()] + $base)->assertSessionHasErrors('log_date');

    $as()->post($store, $base + ['opening_meter' => '1200', 'closing_meter' => '1208', 'idle_hours' => '1.5'])->assertRedirect()->assertSessionHasNoErrors();
    $log = $this->inCompany($this->company, fn () => EquipmentUsageLog::query()->sole());
    expect($log->working_hours)->toBe('8.00')
        ->and($log->idle_hours)->toBe('1.50')
        ->and($log->task_id)->toBe($this->task->id)
        ->and($log->posted_at)->toBeNull()
        ->and($this->costs())->toHaveCount(0);

    $as()->post($store, $base + ['working_hours' => '2'])->assertSessionHasErrors('log_date');

    // The engineer logs but cannot post; the PM posts. Hand check: 8 h × 1,500 = 12,000 (idle not charged).
    $as()->post(route('projects.equipment-usage.post', $this->project), ['ids' => [$log->id]])->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-usage.post', $this->project), ['ids' => [$log->id]])->assertRedirect()->assertSessionHasNoErrors();

    $log->refresh();
    $costs = $this->costs(CostHead::Equipment);
    expect($log->cost_amount)->toBe('12000.00')
        ->and($log->posted_by)->toBe($this->pm->id)
        ->and($costs)->toHaveCount(1)
        ->and($costs[0]->amount)->toBe('12000.00')
        ->and($costs[0]->source_type)->toBe('equipment_usage_log')
        ->and($costs[0]->source_id)->toBe($log->id)
        ->and($costs[0]->task_id)->toBe($this->task->id)
        ->and($costs[0]->boq_item_id)->toBe($this->boqLine->id)
        ->and($costs[0]->boq_line_uid)->toBe($this->boqLine->line_uid)
        ->and($costs[0]->entry_date->toDateString())->toBe(now()->subDay()->toDateString());

    // Retry: posting again changes nothing.
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-usage.post', $this->project), ['ids' => [$log->id]])->assertRedirect();
    $this->inCompany($this->company, fn () => app(EquipmentUsageService::class)->post($this->project, [$log->id], $this->pm));
    expect($this->costs(CostHead::Equipment))->toHaveCount(1);

    // Posted: locked. Reverse, correct to 7 h, re-post → 10,500 (posting ref r1 on the same log).
    $as()->put(route('projects.equipment-usage.update', [$this->project, $log]), ['log_date' => $log->log_date->toDateString(), 'working_hours' => '7'])->assertForbidden();
    $as()->delete(route('projects.equipment-usage.destroy', [$this->project, $log]))->assertForbidden();
    $as()->post(route('projects.equipment-usage.reverse', [$this->project, $log]), ['reason' => 'Meter misread'])->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-usage.reverse', [$this->project, $log]), ['reason' => 'Meter misread'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($log->fresh()->posted_at)->toBeNull()->and($this->netCost(CostHead::Equipment))->toBe('0.00');

    $as()->put(route('projects.equipment-usage.update', [$this->project, $log]), ['log_date' => $log->log_date->toDateString(), 'working_hours' => '7'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->inCompany($this->company, fn () => app(EquipmentUsageService::class)->post($this->project, [$log->id], $this->pm));
    $costs = $this->costs(CostHead::Equipment);
    expect($costs->pluck('amount')->all())->toBe(['12000.00', '-12000.00', '10500.00'])
        ->and($costs->pluck('posting_ref')->all())->toBe(['', '', 'r1'])
        ->and($costs->pluck('source_id')->unique()->all())->toBe([$log->id])
        ->and($this->netCost(CostHead::Equipment))->toBe('10500.00');
});

test('daily-rate equipment posts one day per log whatever the hours', function () {
    $assignment = $this->issueEquipment($this->hiredMixer, 'daily');
    $a = usageLog($this, $assignment, ['log_date' => now()->subDays(2)->toDateString(), 'working_hours' => '3']);
    $b = usageLog($this, $assignment, ['log_date' => now()->subDay()->toDateString(), 'working_hours' => '10', 'idle_hours' => '2']);
    $posted = $this->inCompany($this->company, fn () => app(EquipmentUsageService::class)->post($this->project, [$a->id, $b->id], $this->pm));

    expect($posted)->toBe(2)
        ->and($a->fresh()->cost_amount)->toBe('2000.00')
        ->and($b->fresh()->cost_amount)->toBe('2000.00')
        ->and($this->netCost(CostHead::Equipment))->toBe('4000.00');
});

test('fuel logs compute closing stock and cost but post no project cost', function () {
    $this->issueEquipment($this->excavator);
    $store = route('projects.equipment-fuel.store', $this->project);
    $as = fn () => $this->actingInCompany($this->engineer, $this->company);
    $base = ['equipment_id' => $this->excavator->id, 'log_date' => now()->toDateString()];

    $as()->post($store, $base + ['opening_fuel' => '10', 'fuel_added' => '50', 'fuel_consumed' => '60.01', 'fuel_rate' => '95.5'])->assertSessionHasErrors('fuel_consumed');
    $as()->post($store, ['equipment_id' => $this->hiredMixer->id] + $base + ['fuel_added' => '5'])->assertSessionHasErrors('equipment_id');
    $as()->post($store, ['log_date' => now()->subDays(5)->toDateString()] + $base + ['fuel_added' => '5'])->assertSessionHasErrors('equipment_id');

    $as()->post($store, $base + ['opening_fuel' => '10', 'fuel_added' => '50', 'fuel_consumed' => '40', 'fuel_rate' => '95.5'])->assertRedirect()->assertSessionHasNoErrors();
    $fuel = $this->inCompany($this->company, fn () => EquipmentFuelLog::query()->sole());
    // Hand check: closing = 10 + 50 − 40 = 20 L; cost = 50 × 95.50 = 4,775.00.
    expect($fuel->closing_fuel)->toBe('20.00')
        ->and($fuel->cost)->toBe('4775.00')
        ->and($this->costs())->toHaveCount(0);

    $as()->put(route('projects.equipment-fuel.update', [$this->project, $fuel]), $base + ['opening_fuel' => '20', 'fuel_added' => '30', 'fuel_consumed' => '45', 'fuel_rate' => '96'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($fuel->fresh()->closing_fuel)->toBe('5.00')->and($fuel->fresh()->cost)->toBe('2880.00');

    $this->actingInCompany($this->accountant, $this->company)->post($store, $base + ['fuel_added' => '1'])->assertForbidden();
    $as()->delete(route('projects.equipment-fuel.destroy', [$this->project, $fuel]))->assertRedirect();
    expect($this->inCompany($this->company, fn () => EquipmentFuelLog::query()->count()))->toBe(0)
        ->and($this->costs())->toHaveCount(0);
});

test('repair lifecycle drives equipment status; repair cost is not posted', function () {
    $assignment = $this->issueEquipment($this->excavator);
    $store = route('projects.equipment-repairs.store', $this->project);
    $as = fn () => $this->actingInCompany($this->pm, $this->company);

    $as()->post($store, ['equipment_id' => $this->excavator->id, 'repair_date' => now()->toDateString(), 'description' => 'Hydraulic leak', 'vendor_id' => $this->hireVendor->id, 'cost' => '8500'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $repair = $this->inCompany($this->company, fn () => EquipmentRepair::query()->sole());
    expect($repair->status)->toBe(RepairStatus::Open)
        ->and($repair->cost)->toBe('8500.00')
        ->and($this->excavator->fresh()->status)->toBe(EquipmentStatus::UnderRepair);

    // One open repair at a time; the engineer cannot record repairs; usage while under repair is still allowed on the assignment.
    $as()->post($store, ['equipment_id' => $this->excavator->id, 'repair_date' => now()->toDateString(), 'description' => 'Again'])->assertSessionHasErrors('equipment_id');
    $this->actingInCompany($this->engineer, $this->company)->post($store, ['equipment_id' => $this->hiredMixer->id, 'repair_date' => now()->toDateString(), 'description' => 'X'])->assertForbidden();

    $complete = route('projects.equipment-repairs.complete', [$this->project, $repair]);
    $as()->post($complete, ['completed_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('completed_date');
    $as()->post($complete, ['completed_date' => now()->toDateString(), 'cost' => '9100'])->assertRedirect()->assertSessionHasNoErrors();
    expect($repair->fresh()->status)->toBe(RepairStatus::Completed)
        ->and($repair->fresh()->cost)->toBe('9100.00')
        ->and($this->excavator->fresh()->status)->toBe(EquipmentStatus::Assigned);
    $as()->post($complete, ['completed_date' => now()->toDateString()])->assertForbidden();

    // Returned equipment goes back to available after a cancelled repair.
    $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->returnEquipment($assignment, ['return_date' => now()->toDateString()], $this->pm));
    $as()->post($store, ['equipment_id' => $this->excavator->id, 'repair_date' => now()->toDateString(), 'description' => 'Service'])->assertRedirect();
    $second = $this->inCompany($this->company, fn () => EquipmentRepair::query()->where('description', 'Service')->sole());
    expect($this->excavator->fresh()->status)->toBe(EquipmentStatus::UnderRepair);
    $as()->post(route('projects.equipment-repairs.cancel', [$this->project, $second]), ['reason' => 'Not needed'])->assertRedirect()->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe(RepairStatus::Cancelled)
        ->and($this->excavator->fresh()->status)->toBe(EquipmentStatus::Available)
        ->and($this->costs())->toHaveCount(0);

    // Equipment assigned to another project is repaired there, not here.
    $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->issue($this->otherProject, [
        'equipment_id' => $this->excavator->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'hourly',
    ]));
    $as()->post($store, ['equipment_id' => $this->excavator->id, 'repair_date' => now()->toDateString(), 'description' => 'X'])->assertSessionHasErrors('equipment_id');
});

test('equipment screens are isolated by tenant, project and site membership', function () {
    $assignment = $this->issueEquipment($this->excavator);
    $log = usageLog($this, $assignment, ['working_hours' => '5']);

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    foreach (['equipment-assignments', 'equipment-usage', 'equipment-fuel', 'equipment-repairs'] as $screen) {
        $this->actingInCompany($otherAdmin, $other)->get(route("projects.{$screen}.index", $this->project))->assertNotFound();
    }
    $this->actingInCompany($otherAdmin, $other)->post(route('projects.equipment-usage.reverse', [$this->project, $log]), ['reason' => 'xxxxx'])->assertNotFound();

    // Equipment of another company cannot be issued.
    $foreign = $this->inCompany($other, fn () => Equipment::query()->create([
        'equipment_type_id' => EquipmentType::query()->value('id'), 'code' => 'F-EQ', 'name' => 'Foreign', 'ownership' => 'owned',
        'hourly_rate' => '1', 'daily_rate' => '1', 'status' => 'available', 'is_active' => true,
    ]));
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-assignments.store', $this->project), [
        'equipment_id' => $foreign->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'hourly',
    ])->assertSessionHasErrors('equipment_id');

    // Tower B: its assignment cannot be logged against here, and its log is not reachable here.
    $towerB = $this->inCompany($this->company, fn () => app(EquipmentAssignmentService::class)->issue($this->otherProject, [
        'equipment_id' => $this->hiredMixer->id, 'issue_date' => now()->toDateString(), 'rate_basis' => 'daily',
    ]));
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.equipment-usage.store', $this->project), [
        'equipment_assignment_id' => $towerB->id, 'log_date' => now()->toDateString(), 'working_hours' => '1',
    ])->assertSessionHasErrors('equipment_assignment_id');
    $towerLog = $this->inCompany($this->company, fn () => app(EquipmentUsageService::class)->create($this->otherProject, [
        'equipment_assignment_id' => $towerB->id, 'log_date' => now()->toDateString(), 'working_hours' => '1',
    ]));
    $this->actingInCompany($this->admin, $this->company)->delete(route('projects.equipment-usage.destroy', [$this->project, $towerLog]))->assertNotFound();
    $this->actingInCompany($this->pm, $this->company)->post(route('projects.equipment-usage.post', $this->project), ['ids' => [$towerLog->id]])->assertRedirect();
    expect($towerLog->fresh()->posted_at)->toBeNull();

    // Non-members see nothing; members see their project's rows only.
    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->get(route('projects.equipment-usage.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.equipment-usage.index', $this->project))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Equipment/Usage')->has('logs.data', 1));
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.equipment-usage.index', $this->project))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.data.0.can_update', true)->where('can.post', true));
    foreach (['equipment-assignments', 'equipment-fuel', 'equipment-repairs'] as $screen) {
        $this->actingInCompany($this->pm, $this->company)->get(route("projects.{$screen}.index", $this->project))->assertOk();
    }
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.equipment-assignments.index', $this->project))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Equipment/Assignments')->has('assignments.data', 1)->where('can.create', false));
});
