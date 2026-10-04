<?php

use App\Enums\Quality\NcrStatus;
use App\Models\Masters\Subcontractor;
use App\Models\Quality\Ncr;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

function ncrPayload(array $overrides = []): array
{
    return array_replace([
        'issue' => 'Cover blocks missing at grid C4',
        'severity' => 'major',
        'location' => null,
        'responsible_user_id' => null,
        'subcontractor_id' => null,
        'target_date' => now()->addDays(5)->toDateString(),
    ], $overrides);
}

/**
 * Drive an NCR through start → resolve (engineer) → verify (QE) and optionally close.
 */
function walkNcr($test, Ncr $ncr, bool $close = true): void
{
    $test->actingInCompany($test->engineer, $test->company)->post(route('projects.ncrs.start', [$test->project, $ncr]))->assertSessionHasNoErrors();
    $test->actingInCompany($test->engineer, $test->company)->post(route('projects.ncrs.resolve', [$test->project, $ncr]), [
        'root_cause' => 'Cover blocks not stocked at site',
        'corrective_action' => 'Cover blocks placed at 150 c/c and re-checked',
    ])->assertSessionHasNoErrors();
    $test->actingInCompany($test->qe, $test->company)->post(route('projects.ncrs.verify', [$test->project, $ncr]), ['remarks' => 'Checked on site'])->assertSessionHasNoErrors();
    if ($close) {
        $test->actingInCompany($test->qe, $test->company)->post(route('projects.ncrs.close', [$test->project, $ncr]))->assertSessionHasNoErrors();
    }
}

test('NCRs are numbered per project', function () {
    $first = $this->raiseNcr();
    $second = $this->raiseNcr();

    expect($first->ncr_number)->toBe("NCR-{$this->project->code}-0001")
        ->and($second->ncr_number)->toBe("NCR-{$this->project->code}-0002")
        ->and($first->status)->toBe(NcrStatus::Open);
});

test('an NCR is raised from a failed inspection and inherits its location', function () {
    $failed = $this->failedInspection();

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.ncrs.create', [$this->project, 'inspection' => $failed->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quality/Ncrs/Form')->where('defaultInspectionId', $failed->id)->has('inspections', 1));

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.ncrs.store', $this->project), ncrPayload(['quality_inspection_id' => $failed->id, 'responsible_user_id' => $this->engineer->id]))
        ->assertSessionHasNoErrors();

    $ncr = $this->inCompany($this->company, fn () => Ncr::query()->firstOrFail());
    expect($ncr->quality_inspection_id)->toBe($failed->id)
        ->and($ncr->location)->toBe('Block A slab')
        ->and($ncr->responsible_user_id)->toBe($this->engineer->id);

    $this->actingInCompany($this->qe, $this->company)->get(route('projects.inspections.show', [$this->project, $failed]))
        ->assertInertia(fn ($page) => $page->has('ncrs', 1));
});

test('a passed, open or foreign-project inspection cannot be the source of an NCR', function () {
    $passed = $this->completedInspection();
    $open = $this->requestInspection();
    $url = route('projects.ncrs.store', $this->project);
    $as = fn () => $this->actingInCompany($this->engineer, $this->company);

    $as()->post($url, ncrPayload(['quality_inspection_id' => $passed->id]))->assertSessionHasErrors('quality_inspection_id');
    $as()->post($url, ncrPayload(['quality_inspection_id' => $open->id]))->assertSessionHasErrors('quality_inspection_id');

    $failed = $this->failedInspection();
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.ncrs.store', $this->otherProject), ncrPayload(['quality_inspection_id' => $failed->id]))
        ->assertSessionHasErrors('quality_inspection_id');

    expect(Ncr::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a conditional inspection can raise an NCR and manual NCRs are allowed for raisers', function () {
    $conditional = $this->completedInspection([], 'conditional', 'Curing log pending');
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.ncrs.store', $this->project), ncrPayload(['quality_inspection_id' => $conditional->id]))
        ->assertSessionHasNoErrors();

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.ncrs.store', $this->project), ncrPayload(['issue' => 'Rusted reinforcement stacked in open']))
        ->assertSessionHasNoErrors();

    expect(Ncr::query()->withoutGlobalScopes()->whereNull('quality_inspection_id')->count())->toBe(1);

    $this->actingInCompany($this->director, $this->company)->post(route('projects.ncrs.store', $this->project), ncrPayload())->assertForbidden();
    $this->actingInCompany($this->billing, $this->company)->post(route('projects.ncrs.store', $this->project), ncrPayload())->assertForbidden();
});

test('responsible user must be on the project team and the subcontractor active in this company', function () {
    $url = route('projects.ncrs.store', $this->project);
    $as = fn () => $this->actingInCompany($this->engineer, $this->company);
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);

    $inactive = $this->inCompany($this->company, function () {
        $sub = new Subcontractor;
        $sub->forceFill(['code' => 'SUB-T2', 'name' => 'Dormant Works', 'is_active' => false])->save();

        return $sub;
    });
    $other = $this->createCompany();
    $foreign = $this->inCompany($other, function () {
        $sub = new Subcontractor;
        $sub->forceFill(['code' => 'SUB-X', 'name' => 'Other Co Sub', 'is_active' => true])->save();

        return $sub;
    });

    $as()->post($url, ncrPayload(['responsible_user_id' => $outsider->id]))->assertSessionHasErrors('responsible_user_id');
    $as()->post($url, ncrPayload(['subcontractor_id' => $inactive->id]))->assertSessionHasErrors('subcontractor_id');
    $as()->post($url, ncrPayload(['subcontractor_id' => $foreign->id]))->assertSessionHasErrors('subcontractor_id');
    $as()->post($url, ncrPayload(['target_date' => 'not-a-date']))->assertSessionHasErrors('target_date');
    $as()->post($url, ncrPayload(['severity' => 'catastrophic']))->assertSessionHasErrors('severity');

    $as()->post($url, ncrPayload(['subcontractor_id' => $this->subcontractor->id, 'responsible_user_id' => $this->engineer->id, 'target_date' => '2026-12-15']))->assertSessionHasNoErrors();
    $ncr = $this->inCompany($this->company, fn () => Ncr::query()->firstOrFail());
    expect($ncr->subcontractor_id)->toBe($this->subcontractor->id)
        ->and($ncr->responsible_user_id)->toBe($this->engineer->id)
        ->and($ncr->target_date->toDateString())->toBe('2026-12-15');
});

test('full lifecycle records root cause, corrective action, resolver, verifier and closer', function () {
    $ncr = $this->raiseNcr(['subcontractor_id' => $this->subcontractor->id, 'responsible_user_id' => null], $this->failedInspection());

    walkNcr($this, $ncr);

    $ncr->refresh();
    expect($ncr->status)->toBe(NcrStatus::Closed)
        ->and($ncr->root_cause)->toBe('Cover blocks not stocked at site')
        ->and($ncr->corrective_action)->toBe('Cover blocks placed at 150 c/c and re-checked')
        ->and($ncr->resolved_by)->toBe($this->engineer->id)
        ->and($ncr->verified_by)->toBe($this->qe->id)
        ->and($ncr->verified_at)->not->toBeNull()
        ->and($ncr->verification_remarks)->toBe('Checked on site')
        ->and($ncr->closed_by)->toBe($this->qe->id)
        ->and($ncr->closed_at)->not->toBeNull();
});

test('start needs an assignee and resolve needs root cause and corrective action', function () {
    $ncr = $this->raiseNcr(['responsible_user_id' => null]);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertSessionHasErrors('responsible_user_id');

    $this->actingInCompany($this->engineer, $this->company)->put(route('projects.ncrs.update', [$this->project, $ncr]), ncrPayload(['responsible_user_id' => $this->engineer->id]))->assertSessionHasNoErrors();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertSessionHasNoErrors();

    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.resolve', [$this->project, $ncr]), ['root_cause' => 'x'])->assertSessionHasErrors('corrective_action');
    expect($ncr->fresh()->status)->toBe(NcrStatus::InProgress);
});

test('the resolver can never verify, even as super admin', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $ncr = $this->raiseNcr();
    $this->actingInCompany($superAdmin, $this->company)->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertSessionHasNoErrors();
    $this->actingInCompany($superAdmin, $this->company)->post(route('projects.ncrs.resolve', [$this->project, $ncr]), ['root_cause' => 'Cause', 'corrective_action' => 'Action'])->assertSessionHasNoErrors();

    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.ncrs.show', [$this->project, $ncr]))
        ->assertInertia(fn ($page) => $page->where('can.verify', false));
    $this->actingInCompany($superAdmin, $this->company)->post(route('projects.ncrs.verify', [$this->project, $ncr]))->assertSessionHasErrors('ncr');
    expect($ncr->fresh()->status)->toBe(NcrStatus::Resolved);

    $this->actingInCompany($this->qe, $this->company)->post(route('projects.ncrs.verify', [$this->project, $ncr]))->assertSessionHasNoErrors();
    expect($ncr->fresh()->verified_by)->toBe($this->qe->id);
});

test('a rejected resolution goes back to in progress with the reason recorded', function () {
    $ncr = $this->raiseNcr();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.start', [$this->project, $ncr]));
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.resolve', [$this->project, $ncr]), ['root_cause' => 'Cause', 'corrective_action' => 'Action']);

    $this->actingInCompany($this->qe, $this->company)->post(route('projects.ncrs.reopen', [$this->project, $ncr]), ['reason' => 'Blocks still missing at C5'])->assertSessionHasNoErrors();
    $ncr->refresh();
    expect($ncr->status)->toBe(NcrStatus::InProgress)
        ->and($ncr->resolved_by)->toBeNull()
        ->and($ncr->verification_remarks)->toBe('Blocks still missing at C5');
});

test('invalid transitions are rejected by the server even for a super admin', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $as = fn () => $this->actingInCompany($superAdmin, $this->company);
    $ncr = $this->raiseNcr();

    $as()->post(route('projects.ncrs.close', [$this->project, $ncr]))->assertSessionHasErrors('ncr');
    $as()->post(route('projects.ncrs.verify', [$this->project, $ncr]))->assertSessionHasErrors('ncr');
    $as()->post(route('projects.ncrs.resolve', [$this->project, $ncr]), ['root_cause' => 'C', 'corrective_action' => 'A'])->assertSessionHasErrors('ncr');
    $as()->post(route('projects.ncrs.reopen', [$this->project, $ncr]), ['reason' => 'Not resolved yet'])->assertSessionHasErrors('ncr');
    expect($ncr->fresh()->status)->toBe(NcrStatus::Open);

    $as()->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertSessionHasNoErrors();
    $as()->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertSessionHasErrors('ncr');
    $as()->delete(route('projects.ncrs.destroy', [$this->project, $ncr]))->assertSessionHasErrors('ncr');
    expect($ncr->fresh()->status)->toBe(NcrStatus::InProgress);
});

test('permissions: only quality engineers verify and close', function () {
    $ncr = $this->raiseNcr();
    walkNcr($this, $ncr, false);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.close', [$this->project, $ncr]))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->post(route('projects.ncrs.close', [$this->project, $ncr]))->assertForbidden();

    $other = $this->raiseNcr();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.start', [$this->project, $other]));
    $this->actingInCompany($this->qe, $this->company)->post(route('projects.ncrs.resolve', [$this->project, $other]), ['root_cause' => 'C', 'corrective_action' => 'A'])->assertSessionHasNoErrors();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.ncrs.verify', [$this->project, $other]))->assertForbidden();
});

test('a closed NCR is locked at the server and model level', function () {
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $ncr = $this->raiseNcr();
    walkNcr($this, $ncr);

    $this->actingInCompany($superAdmin, $this->company)->get(route('projects.ncrs.show', [$this->project, $ncr]))
        ->assertInertia(fn ($page) => $page->where('can', ['update' => false, 'delete' => false, 'start' => false, 'resolve' => false, 'verify' => false, 'reopen' => false, 'close' => false, 'attach' => false]));
    $this->actingInCompany($superAdmin, $this->company)->put(route('projects.ncrs.update', [$this->project, $ncr]), ncrPayload(['issue' => 'Changed']))->assertSessionHasErrors();
    $this->actingInCompany($superAdmin, $this->company)->post(route('projects.ncrs.reopen', [$this->project, $ncr]), ['reason' => 'Try to reopen'])->assertSessionHasErrors('ncr');

    $fresh = $ncr->fresh();
    expect($fresh->issue)->toBe('Cover blocks missing at grid C4')
        ->and(fn () => $fresh->forceFill(['status' => NcrStatus::Open])->save())->toThrow(ValidationException::class)
        ->and(fn () => $ncr->fresh()->forceFill(['verification_remarks' => 'edited'])->save())->toThrow(ValidationException::class);
});

test('NCRs are tenant and project isolated', function () {
    $ncr = $this->raiseNcr();
    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $outsider = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);

    $this->actingInCompany($otherAdmin, $other)->get(route('projects.ncrs.show', [$this->project, $ncr]))->assertNotFound();
    $this->actingInCompany($otherAdmin, $other)->post(route('projects.ncrs.start', [$this->project, $ncr]))->assertNotFound();
    $this->actingInCompany($outsider, $this->company)->get(route('projects.ncrs.show', [$this->project, $ncr]))->assertForbidden();
    $this->actingInCompany($this->qe, $this->company)->get(route('projects.ncrs.show', [$this->otherProject, $ncr]))->assertNotFound();

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.ncrs.index', $this->project))
        ->assertInertia(fn ($page) => $page->where('ncrs.total', 1));
    $this->actingInCompany($this->qe, $this->company)->get(route('projects.ncrs.index', $this->otherProject))
        ->assertInertia(fn ($page) => $page->where('ncrs.total', 0));
});
