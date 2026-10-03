<?php

use App\Enums\Crm\LeadStatus;
use App\Models\Core\Role;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Leads are company-level. "Sales Executive" is a custom role with crm.leads.* and
 * crm.quotations.* except convert (no default role other than Company Admin creates leads).
 */
beforeEach(function () {
    $this->company = $this->createCompany(['state_code' => '27']);
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $role = Role::query()->create(['team_id' => $this->company->id, 'name' => 'Sales Executive', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissionNames(['dashboard.view', 'crm.leads.view', 'crm.leads.create', 'crm.leads.update', 'crm.leads.delete',
        'crm.quotations.view', 'crm.quotations.create', 'crm.quotations.update', 'crm.quotations.delete', 'crm.clients.view']);
    $this->sales = $this->createMember($this->company, 'Sales Executive');
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
});

function leadPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Anil Mehta',
        'company_name' => 'Mehta Realty',
        'mobile' => '+91 98200 12345',
        'email' => 'anil@mehtarealty.test',
        'source' => 'Referral',
        'project_type' => 'Residential',
        'location' => 'Thane West',
        'state_code' => '27',
        'estimated_value' => '25000000.50',
        'expected_close_date' => now()->addMonths(2)->toDateString(),
        'notes' => 'G+12 tower, two wings',
    ], $overrides);
}

function storeLead($test, array $overrides = [], $user = null): Lead
{
    $test->actingInCompany($user ?? $test->sales, $test->company)->post(route('crm.leads.store'), leadPayload($overrides))->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => Lead::query()->latest('id')->firstOrFail());
}

test('leads are numbered per year and can be created, edited and deleted', function () {
    $lead = storeLead($this, ['assigned_to' => $this->sales->id]);
    $year = now()->format('Y');

    expect($lead->lead_number)->toBe("LEAD-{$year}-0001")
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->company_id)->toBe($this->company->id)
        ->and($lead->estimated_value)->toBe('25000000.50')
        ->and($lead->assigned_to)->toBe($this->sales->id);
    expect(storeLead($this, ['name' => 'Second'])->lead_number)->toBe("LEAD-{$year}-0002");

    $this->actingInCompany($this->sales, $this->company)->put(route('crm.leads.update', $lead), leadPayload(['name' => 'Anil K. Mehta', 'estimated_value' => '30000000']))
        ->assertSessionHasNoErrors();
    expect($lead->fresh()->name)->toBe('Anil K. Mehta')->and($lead->fresh()->estimated_value)->toBe('30000000.00');

    $this->actingInCompany($this->sales, $this->company)->get(route('crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Crm/Leads/Show')->where('lead.lead_number', "LEAD-{$year}-0001")->where('can.quote', true));

    $this->actingInCompany($this->sales, $this->company)->delete(route('crm.leads.destroy', $lead))->assertRedirect();
    expect($this->inCompany($this->company, fn () => Lead::query()->count()))->toBe(1);
});

test('status moves between open stages; lost needs a reason; won only comes from an accepted quotation', function () {
    $lead = storeLead($this);
    $patch = fn (array $data) => $this->actingInCompany($this->sales, $this->company)->patch(route('crm.leads.status', $lead), $data);

    $patch(['status' => 'qualified'])->assertSessionHasNoErrors();
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);

    $patch(['status' => 'won'])->assertSessionHasErrors('status');
    $patch(['status' => 'lost'])->assertSessionHasErrors('lost_reason');
    $patch(['status' => 'lost', 'lost_reason' => 'x'])->assertSessionHasErrors('lost_reason');
    $patch(['status' => 'lost', 'lost_reason' => 'Went with a cheaper contractor'])->assertSessionHasNoErrors();
    expect($lead->fresh()->status)->toBe(LeadStatus::Lost)->and($lead->fresh()->lost_reason)->toBe('Went with a cheaper contractor');

    // Reopening clears the reason.
    $patch(['status' => 'contacted'])->assertSessionHasNoErrors();
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted)->and($lead->fresh()->lost_reason)->toBeNull();
    $patch(['status' => 'nonsense'])->assertSessionHasErrors('status');
});

test('activities are logged with dates validated; a call moves a new lead to contacted', function () {
    $lead = storeLead($this);
    $log = fn (array $data) => $this->actingInCompany($this->sales, $this->company)->post(route('crm.leads.activities.store', $lead), $data);

    $log(['type' => 'note', 'activity_at' => now()->subHour()->format('Y-m-d H:i'), 'summary' => 'Found via referral'])->assertSessionHasNoErrors();
    expect($lead->fresh()->status)->toBe(LeadStatus::New);

    $log(['type' => 'call', 'activity_at' => now()->addDay()->format('Y-m-d H:i'), 'summary' => 'Future call'])->assertSessionHasErrors('activity_at');
    $log(['type' => 'visit', 'activity_at' => now()->subMinutes(5)->format('Y-m-d H:i'), 'summary' => 'Site visit', 'next_follow_up' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('next_follow_up');
    $log(['type' => 'sms', 'activity_at' => now()->subMinutes(5)->format('Y-m-d H:i'), 'summary' => 'x'])->assertSessionHasErrors('type');

    $log(['type' => 'call', 'activity_at' => now()->subMinutes(5)->format('Y-m-d H:i'), 'summary' => 'Discussed scope', 'next_follow_up' => now()->addDays(3)->toDateString()])
        ->assertSessionHasNoErrors();
    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted)
        ->and($this->inCompany($this->company, fn () => LeadActivity::query()->where('lead_id', $lead->id)->count()))->toBe(2);

    $this->actingInCompany($this->sales, $this->company)->get(route('crm.leads.show', $lead))
        ->assertInertia(fn (Assert $page) => $page->has('activities', 2));
});

test('a lead can only be assigned to an active member of the same company', function () {
    $other = $this->createCompany();
    $foreigner = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($this->sales, $this->company)->post(route('crm.leads.store'), leadPayload(['assigned_to' => $foreigner->id]))
        ->assertSessionHasErrors('assigned_to');
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.leads.store'), leadPayload(['assigned_to' => 999999]))
        ->assertSessionHasErrors('assigned_to');
    expect($this->inCompany($this->company, fn () => Lead::query()->count()))->toBe(0);

    // A client of another company cannot be linked either.
    $foreignClient = $this->inCompany($other, fn () => Client::query()->create(['code' => 'CLX', 'company_name' => 'Foreign client']));
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.leads.store'), leadPayload(['client_id' => $foreignClient->id]))
        ->assertSessionHasErrors('client_id');
});

test('leads need crm.leads permissions and are isolated per company', function () {
    $lead = storeLead($this);

    $this->actingInCompany($this->engineer, $this->company)->get(route('crm.leads.index'))->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('crm.leads.store'), leadPayload())->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->patch(route('crm.leads.status', $lead), ['status' => 'qualified'])->assertForbidden();

    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('crm.leads.show', $lead))->assertNotFound();
    $this->actingInCompany($outsider, $other)->put(route('crm.leads.update', $lead), leadPayload())->assertNotFound();
    $this->actingInCompany($outsider, $other)->get(route('crm.leads.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Crm/Leads/Index')->has('leads.data', 0));
});
