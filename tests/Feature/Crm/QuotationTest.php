<?php

use App\Enums\Crm\LeadStatus;
use App\Enums\Crm\QuotationStatus;
use App\Models\Boq\Boq;
use App\Models\Core\AuditLog;
use App\Models\Core\Role;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Quotation;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Services\Crm\QuotationService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Company in Maharashtra (27). The sales executive drafts and sends; the director records the
 * client's decision (crm.quotations.approve); only the company admin holds crm.quotations.convert.
 */
beforeEach(function () {
    $this->company = $this->createCompany(['state_code' => '27']);
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->director = $this->createMember($this->company, DefaultRoles::DIRECTOR);
    $this->pm = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER);
    $role = Role::query()->create(['team_id' => $this->company->id, 'name' => 'Sales Executive', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissionNames(['dashboard.view', 'crm.leads.view', 'crm.leads.create', 'crm.leads.update',
        'crm.quotations.view', 'crm.quotations.create', 'crm.quotations.update', 'crm.quotations.delete', 'crm.clients.view']);
    $this->sales = $this->createMember($this->company, 'Sales Executive');

    $this->inCompany($this->company, function () {
        $this->lead = Lead::query()->forceCreate([
            'lead_number' => 'LEAD-TEST-0001', 'name' => 'Anil Mehta', 'company_name' => 'Mehta Realty', 'mobile' => '9820012345',
            'email' => 'anil@mehtarealty.test', 'state_code' => '27', 'status' => LeadStatus::Qualified, 'estimated_value' => '0',
        ]);
        $this->gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
        $this->lumpsum = Unit::query()->where('symbol', 'LS')->value('id') ?? Unit::query()->value('id');
    });
});

function crmQuotationPayload($test, array $overrides = []): array
{
    return array_replace([
        'lead_id' => $test->lead->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'title' => 'Civil works for Mehta Heights',
        'project_name' => 'Mehta Heights',
        'project_type' => 'Residential',
        'site_address' => 'Plot 12, Thane West',
        'city' => 'Thane',
        'place_of_supply_state' => '27',
        'terms' => '50% advance',
        'subtotal' => '1', 'total_amount' => '1', // ignored: totals are computed on the server
        'items' => [
            ['description' => 'Civil works (lump sum)', 'hsn_sac' => '995411', 'unit_id' => $test->lumpsum, 'quantity' => '1', 'rate' => '1000000', 'discount_percent' => '2.5', 'tax_rate_id' => $test->gst18],
            ['description' => 'Structural design fee', 'quantity' => '2.5', 'rate' => '12345.678'],
        ],
    ], $overrides);
}

function storeQuotation($test, array $overrides = [], $user = null): Quotation
{
    $test->actingInCompany($user ?? $test->sales, $test->company)->post(route('crm.quotations.store'), crmQuotationPayload($test, $overrides))->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => Quotation::query()->latest('id')->firstOrFail());
}

function acceptedQuotation($test, array $overrides = []): Quotation
{
    $quotation = storeQuotation($test, $overrides);
    $test->actingInCompany($test->sales, $test->company)->post(route('crm.quotations.send', $quotation))->assertSessionHasNoErrors();
    $test->actingInCompany($test->director, $test->company)->post(route('crm.quotations.accept', $quotation))->assertSessionHasNoErrors();

    return $quotation->fresh();
}

test('quotation totals are computed on the server with intra-state GST (hand-checked)', function () {
    $quotation = storeQuotation($this);
    $year = now()->format('Y');

    // Line 1: 1,000,000 − 2.5 % = 975,000 taxable; CGST 9 % 87,750 + SGST 87,750.
    // Line 2: 2.5 × 12,345.678 = 30,864.195 → 30,864.20, no GST.
    expect($quotation->quotation_number)->toBe("QTN-{$year}-0001")
        ->and($quotation->revision)->toBe(0)
        ->and($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->tax_type->value)->toBe('intra')
        ->and($quotation->subtotal)->toBe('1030864.20')
        ->and($quotation->discount_amount)->toBe('25000.00')
        ->and($quotation->taxable_amount)->toBe('1005864.20')
        ->and($quotation->cgst_amount)->toBe('87750.00')
        ->and($quotation->sgst_amount)->toBe('87750.00')
        ->and($quotation->igst_amount)->toBe('0.00')
        ->and($quotation->total_amount)->toBe('1181364.20');

    $this->actingInCompany($this->sales, $this->company)->put(route('crm.quotations.update', $quotation), crmQuotationPayload($this, ['place_of_supply_state' => '29']))
        ->assertSessionHasNoErrors();
    $quotation = $quotation->fresh();
    expect($quotation->tax_type->value)->toBe('inter')
        ->and($quotation->igst_amount)->toBe('175500.00')
        ->and($quotation->cgst_amount)->toBe('0.00')
        ->and($quotation->total_amount)->toBe('1181364.20');

    // A draft can be deleted.
    $this->actingInCompany($this->sales, $this->company)->delete(route('crm.quotations.destroy', $quotation))->assertRedirect();
    expect($this->inCompany($this->company, fn () => Quotation::query()->count()))->toBe(0);
});

test('sending marks the lead quoted and locks the quotation; a revision is a new draft version', function () {
    $quotation = storeQuotation($this);
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.send', $quotation))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent)
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::Quoted);

    $this->actingInCompany($this->sales, $this->company)->put(route('crm.quotations.update', $quotation), crmQuotationPayload($this))->assertForbidden();

    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.revise', $quotation))->assertRedirect();
    $revision = $this->inCompany($this->company, fn () => Quotation::query()->latest('id')->firstOrFail());
    expect($revision->id)->not->toBe($quotation->id)
        ->and($revision->quotation_number)->toBe($quotation->quotation_number)
        ->and($revision->revision)->toBe(1)
        ->and($revision->parent_quotation_id)->toBe($quotation->id)
        ->and($revision->status)->toBe(QuotationStatus::Draft)
        ->and($revision->total_amount)->toBe('1181364.20')
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Revised)
        ->and($this->inCompany($this->company, fn () => $revision->items()->count()))->toBe(2);

    // The revision is edited and re-sent; the old version stays frozen.
    $this->actingInCompany($this->sales, $this->company)->put(route('crm.quotations.update', $revision), crmQuotationPayload($this, ['items' => [
        ['description' => 'Civil works (lump sum, revised)', 'quantity' => '1', 'rate' => '950000', 'tax_rate_id' => $this->gst18],
    ]]))->assertSessionHasNoErrors();
    expect($revision->fresh()->total_amount)->toBe('1121000.00')
        ->and($quotation->fresh()->total_amount)->toBe('1181364.20');
    $this->actingInCompany($this->sales, $this->company)->delete(route('crm.quotations.destroy', $revision))->assertForbidden();

    $this->actingInCompany($this->sales, $this->company)->get(route('crm.quotations.show', $revision))
        ->assertInertia(fn (Assert $page) => $page->component('Crm/Quotations/Show')->has('versions', 2));
});

test('decisions need crm.quotations.approve; rejection needs a reason; accepted quotations are immutable', function () {
    $quotation = storeQuotation($this);
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.send', $quotation));

    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.accept', $quotation))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.reject', $quotation), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.accept', $quotation))->assertSessionHasNoErrors();

    $quotation = $quotation->fresh();
    expect($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->decided_by)->toBe($this->director->id)
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::Won);

    $this->actingInCompany($this->admin, $this->company)->put(route('crm.quotations.update', $quotation), crmQuotationPayload($this))->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.revise', $quotation))->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.reject', $quotation), ['reason' => 'Changed mind'])->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->delete(route('crm.quotations.destroy', $quotation))->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(QuotationService::class)->update($quotation->fresh(), crmQuotationPayload($this))))
        ->toThrow(Exception::class);
});

test('rejected and expired quotations can be revised; an expired validity blocks acceptance', function () {
    $rejected = storeQuotation($this);
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.send', $rejected));
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.reject', $rejected), ['reason' => 'Price too high'])->assertSessionHasNoErrors();
    expect($rejected->fresh()->status)->toBe(QuotationStatus::Rejected)->and($rejected->fresh()->rejection_reason)->toBe('Price too high');
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.revise', $rejected))->assertRedirect();

    $late = storeQuotation($this, ['valid_until' => now()->addDays(5)->toDateString()]);
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.send', $late));
    $this->travel(6)->days();
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.accept', $late))->assertSessionHasErrors('quotation');
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.expire', $late))->assertSessionHasNoErrors();
    expect($late->fresh()->status)->toBe(QuotationStatus::Expired);
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.revise', $late))->assertRedirect();
    $revision = $this->inCompany($this->company, fn () => Quotation::query()->latest('id')->firstOrFail());
    expect($revision->valid_until)->toBeNull(); // a lapsed validity is not carried over
});

test('converting an accepted quotation creates one project and a client from the lead, without a BOQ', function () {
    $quotation = acceptedQuotation($this);
    $projectsBefore = $this->inCompany($this->company, fn () => Project::query()->count());

    // The sales executive lacks crm.quotations.convert.
    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.convert', $quotation), ['code' => 'MEHTA1'])->assertForbidden();

    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.convert', $quotation), [
        'code' => 'mehta1', 'project_manager_id' => $this->pm->id, 'start_date' => now()->toDateString(), 'expected_end_date' => now()->addYear()->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('crm.quotations.show', $quotation));

    $quotation = $quotation->fresh();
    [$project, $client] = $this->inCompany($this->company, fn () => [Project::query()->findOrFail($quotation->converted_project_id), Client::query()->findOrFail($quotation->client_id)]);
    expect($project->code)->toBe('MEHTA1')
        ->and($project->name)->toBe('Mehta Heights')
        ->and($project->company_id)->toBe($this->company->id)
        ->and($project->client_id)->toBe($client->id)
        ->and($project->project_manager_id)->toBe($this->pm->id)
        ->and($project->state_code)->toBe('27')
        ->and($project->contract_value)->toBe('1005864.20')
        ->and($client->code)->toBe('CLI-0001')
        ->and($client->company_name)->toBe('Mehta Realty')
        ->and($client->contact_person)->toBe('Anil Mehta')
        ->and($this->lead->fresh()->client_id)->toBe($client->id)
        ->and($quotation->converted_by)->toBe($this->admin->id)
        ->and($this->inCompany($this->company, fn () => Boq::query()->where('project_id', $project->id)->count()))->toBe(0)
        ->and($this->inCompany($this->company, fn () => AuditLog::query()->where('event', 'converted')->where('auditable_id', $quotation->id)->count()))->toBe(1);

    // Idempotent: a second request or a direct retry returns the same project.
    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.convert', $quotation), ['code' => 'MEHTA2'])
        ->assertSessionHasNoErrors()->assertRedirect(route('crm.quotations.show', $quotation));
    $again = $this->inCompany($this->company, fn () => app(QuotationService::class)->convert($quotation->fresh(), $this->admin, ['code' => 'MEHTA3']));
    expect($again->id)->toBe($project->id)
        ->and($this->inCompany($this->company, fn () => Project::query()->count()))->toBe($projectsBefore + 1)
        ->and($this->inCompany($this->company, fn () => Client::query()->count()))->toBe(1);

    $this->actingInCompany($this->admin, $this->company)->get(route('crm.quotations.show', $quotation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('quotation.converted_project.id', $project->id)->where('can.convert', false)->where('convert', null));
});

test('ability flags follow the quotation state even for a platform super admin', function () {
    $root = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN, ['is_super_admin' => true]);
    $flags = fn (Quotation $q) => $this->actingInCompany($root, $this->company)->get(route('crm.quotations.show', $q))->assertOk()
        ->viewData('page')['props']['can'];

    $quotation = storeQuotation($this);
    expect($flags($quotation))->toMatchArray(['update' => true, 'delete' => true, 'send' => true, 'decide' => false, 'revise' => false, 'convert' => false, 'attach' => true]);

    $this->actingInCompany($this->sales, $this->company)->post(route('crm.quotations.send', $quotation))->assertSessionHasNoErrors();
    expect($flags($quotation->fresh()))->toMatchArray(['update' => false, 'delete' => false, 'send' => false, 'decide' => true, 'revise' => true, 'convert' => false, 'attach' => false]);

    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.accept', $quotation))->assertSessionHasNoErrors();
    expect($flags($quotation->fresh()))->toMatchArray(['update' => false, 'delete' => false, 'send' => false, 'decide' => false, 'revise' => false, 'convert' => true]);

    $this->actingInCompany($root, $this->company)->post(route('crm.quotations.convert', $quotation), ['code' => 'ROOT1'])->assertSessionHasNoErrors();
    expect(array_filter($flags($quotation->fresh())))->toBe([]);

    // The services enforce the state too, so the super admin cannot edit or delete the accepted quotation.
    $this->actingInCompany($root, $this->company)->put(route('crm.quotations.update', $quotation), crmQuotationPayload($this, ['title' => 'Changed']))
        ->assertSessionHasErrors();
    $this->actingInCompany($root, $this->company)->delete(route('crm.quotations.destroy', $quotation))->assertSessionHasErrors();
    expect($quotation->fresh()->title)->toBe('Civil works for Mehta Heights')
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});

test('conversion reuses the existing client and needs an accepted quotation', function () {
    $client = $this->inCompany($this->company, fn () => Client::query()->create(['code' => 'CL-MEHTA', 'company_name' => 'Mehta Realty Pvt Ltd', 'state_code' => '27']));

    $draft = storeQuotation($this, ['client_id' => $client->id]);
    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.convert', $draft), [])->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(QuotationService::class)->convert($draft->fresh(), $this->admin, [])))->toThrow(Exception::class);

    $quotation = acceptedQuotation($this, ['client_id' => $client->id, 'project_name' => 'Mehta Annexe']);
    $this->actingInCompany($this->admin, $this->company)->post(route('crm.quotations.convert', $quotation), [])->assertSessionHasNoErrors();

    $project = $this->inCompany($this->company, fn () => Project::query()->findOrFail($quotation->fresh()->converted_project_id));
    expect($project->client_id)->toBe($client->id)
        ->and($project->code)->toStartWith('PRJ')
        ->and($this->inCompany($this->company, fn () => Client::query()->count()))->toBe(1);
});

test('quotations are isolated per company and need crm.quotations permissions', function () {
    $quotation = storeQuotation($this);
    $engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($engineer, $this->company)->get(route('crm.quotations.index'))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->post(route('crm.quotations.store'), crmQuotationPayload($this))->assertForbidden(); // view + approve only

    $other = $this->createCompany(['state_code' => '29']);
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('crm.quotations.show', $quotation))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('crm.quotations.send', $quotation))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('crm.quotations.convert', $quotation), [])->assertNotFound();

    // A lead of another company cannot be quoted.
    $this->actingInCompany($outsider, $other)->post(route('crm.quotations.store'), crmQuotationPayload($this))->assertSessionHasErrors('lead_id');
    expect($this->inCompany($other, fn () => Quotation::query()->count()))->toBe(0);
});
