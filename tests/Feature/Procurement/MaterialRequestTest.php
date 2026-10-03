<?php

use App\Enums\Procurement\MaterialRequestStatus;
use App\Events\Procurement\MaterialRequestSubmitted;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Procurement\MaterialRequest;
use App\Notifications\Procurement\ProcurementNotification;
use App\Services\Planning\PlanningService;
use App\Services\Procurement\MaterialRequestService;
use App\Services\Procurement\RfqService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
});

function mrOf($test, ?int $id = null): MaterialRequest
{
    return $test->inCompany($test->company, fn () => $id ? MaterialRequest::query()->findOrFail($id) : MaterialRequest::query()->latest('id')->firstOrFail());
}

test('a site engineer raises a multi-line material request that starts as a numbered draft', function () {
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.material-requests.store', $this->project), $this->mrPayload(['status' => 'approved', 'requested_by' => $this->admin->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $mr = mrOf($this);
    $items = $this->inCompany($this->company, fn () => $mr->items()->orderBy('sort_order')->get());

    expect($mr->request_number)->toBe('MR-PRJ001-0001')
        ->and($mr->status)->toBe(MaterialRequestStatus::Draft)
        ->and($mr->requested_by)->toBe($this->engineer->id)
        ->and($items)->toHaveCount(2)
        ->and($items[0]->quantity)->toBe('100.0000')
        ->and($items[0]->boq_item_id)->toBe($this->boqItemId())
        ->and($items[0]->ordered_qty)->toBe('0.0000')
        ->and($items[1]->material_id)->toBe($this->steel->id);
});

test('material request lines are validated against company masters, the approved BOQ and project tasks', function () {
    $as = $this->actingInCompany($this->engineer, $this->company);
    $line = fn (array $extra) => ['items' => [array_replace(['material_id' => $this->cement->id, 'unit_id' => $this->cement->unit_id, 'quantity' => '10'], $extra)]];

    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['quantity' => '0'])))->assertSessionHasErrors('items.0.quantity');
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['quantity' => '-5'])))->assertSessionHasErrors('items.0.quantity');
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload(['required_date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('required_date');

    $other = $this->createCompany();
    $foreignMaterial = $this->inCompany($other, fn () => Material::query()->create(['code' => 'X1', 'name' => 'Foreign', 'unit_id' => Unit::query()->value('id')]));
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['material_id' => $foreignMaterial->id])))->assertSessionHasErrors('items.0.material_id');

    // A BOQ line of a draft (not approved) BOQ, and one of another project, are both rejected.
    $draftBoq = $this->makeBoq(title: 'Draft finishing');
    $draftLine = $this->inCompany($this->company, fn () => $draftBoq->items()->value('id'));
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['boq_item_id' => $draftLine])))->assertSessionHasErrors('items.0.boq_item_id');

    $mall = $this->makeTeamProject('Mall');
    $mallTask = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($mall, ['name' => 'Mall slab']));
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['task_id' => $mallTask->id])))->assertSessionHasErrors('items.0.task_id');

    $ownTask = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, ['name' => 'Raft']));
    $as->post(route('projects.material-requests.store', $this->project), $this->mrPayload($line(['task_id' => $ownTask->id, 'boq_item_id' => $this->boqItemId()])))
        ->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => MaterialRequest::query()->count()))->toBe(1);
});

test('submitting starts the approval workflow, notifies purchasing and approval makes the request procurable', function () {
    Notification::fake();
    $mr = $this->makeMr();

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.material-requests.submit', [$this->project, $mr]))
        ->assertSessionHasNoErrors();

    expect(mrOf($this, $mr->id)->status)->toBe(MaterialRequestStatus::Submitted);
    Notification::assertSentTo($this->purchaser, ProcurementNotification::class);
    Notification::assertNotSentTo($this->engineer, ProcurementNotification::class);

    $request = $this->inCompany($this->company, fn () => mrOf($this, $mr->id)->pendingApprovalRequest());
    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request->id), ['comments' => 'OK'])->assertSessionHasNoErrors();
    expect(mrOf($this, $mr->id)->status)->toBe(MaterialRequestStatus::Submitted);

    $this->actingInCompany($this->purchaser, $this->company)->post(route('approvals.approve', $request->id))->assertSessionHasNoErrors();
    $approved = mrOf($this, $mr->id);

    expect($approved->status)->toBe(MaterialRequestStatus::Approved)
        ->and($approved->approved_by)->toBe($this->purchaser->id)
        ->and($approved->approved_at)->not->toBeNull();
});

test('the submitted event is dispatched once per submission', function () {
    Event::fake([MaterialRequestSubmitted::class]);
    $mr = $this->makeMr();

    $this->inCompany($this->company, fn () => app(MaterialRequestService::class)->submit($mr, $this->engineer));

    Event::assertDispatchedTimes(MaterialRequestSubmitted::class, 1);
});

test('users cannot set derived statuses or quantities; approved requests are locked against forged edits', function () {
    $mr = $this->approvedMr();
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->put(route('projects.material-requests.update', [$this->project, $mr]), $this->mrPayload())->assertForbidden();
    $as->delete(route('projects.material-requests.destroy', [$this->project, $mr]))->assertForbidden();
    $this->actingInCompany($this->admin, $this->company)->put(route('projects.material-requests.update', [$this->project, $mr]), $this->mrPayload())->assertForbidden();

    $this->inCompany($this->company, function () use ($mr) {
        $fresh = MaterialRequest::query()->findOrFail($mr->id);
        expect(fn () => $fresh->update(['remarks' => 'changed']))->toThrow(ValidationException::class);
        $line = $fresh->items()->first();
        expect(fn () => $line->forceFill(['quantity' => '999'])->save())->toThrow(ValidationException::class);
    });

    expect(mrOf($this, $mr->id)->remarks)->toBe('For raft foundation');
});

test('a draft can be edited by its project team and deleted by the project manager, but not by the store manager', function () {
    $mr = $this->makeMr();

    $this->actingInCompany($this->storekeeper, $this->company)
        ->put(route('projects.material-requests.update', [$this->project, $mr]), $this->mrPayload())
        ->assertForbidden();

    $this->actingInCompany($this->engineer, $this->company)
        ->put(route('projects.material-requests.update', [$this->project, $mr]), $this->mrPayload(['priority' => 'urgent', 'items' => [
            ['material_id' => $this->cement->id, 'unit_id' => $this->cement->unit_id, 'quantity' => '120'],
        ]]))
        ->assertSessionHasNoErrors();

    $fresh = mrOf($this, $mr->id);
    expect($fresh->priority->value)->toBe('urgent')
        ->and($this->inCompany($this->company, fn () => $fresh->items()->pluck('quantity')->all()))->toBe(['120.0000']);

    // Site engineers have no material_requests.delete permission by default.
    $this->actingInCompany($this->engineer, $this->company)->delete(route('projects.material-requests.destroy', [$this->project, $mr]))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)->delete(route('projects.material-requests.destroy', [$this->project, $mr]))->assertRedirect();
    expect($this->inCompany($this->company, fn () => MaterialRequest::query()->count()))->toBe(0);
});

test('an approved request can be cancelled only while nothing is on an RFQ or purchase order', function () {
    $mr = $this->approvedMr();
    $rfq = $this->sentRfq($mr, send: false);

    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.material-requests.cancel', [$this->project, $mr]), ['reason' => 'Design changed'])
        ->assertSessionHasErrors('material_request');

    $this->inCompany($this->company, fn () => app(RfqService::class)->delete($rfq));

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.material-requests.cancel', [$this->project, $mr]), ['reason' => 'Design changed'])
        ->assertForbidden();
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.material-requests.cancel', [$this->project, $mr]), ['reason' => 'Design changed'])
        ->assertSessionHasNoErrors();

    expect(mrOf($this, $mr->id)->status)->toBe(MaterialRequestStatus::Cancelled)
        ->and(mrOf($this, $mr->id)->cancelled_reason)->toBe('Design changed');
});

test('the index lists requests with item counts and ordered progress', function () {
    $this->approvedMr();
    $this->makeMr(['priority' => 'low']);

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.material-requests.index', [$this->project, 'priority' => 'high']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/MaterialRequests/Index')
            ->has('requests.data', 1)
            ->where('requests.data.0.request_number', 'MR-PRJ001-0001')
            ->where('requests.data.0.status', 'approved')
            ->where('requests.data.0.items_count', 2)
            ->where('requests.data.0.ordered_percent', '0')
            ->where('can.create', true));
});

test('the show page exposes remaining quantities and the create-RFQ action only when procurable', function () {
    $mr = $this->approvedMr();

    $this->actingInCompany($this->purchaser, $this->company)
        ->get(route('projects.material-requests.show', [$this->project, $mr]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/MaterialRequests/Show')
            ->where('items.0.remaining_qty', '100.0000')
            ->where('can.create_rfq', true)
            ->where('can.update', false));

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('projects.material-requests.show', [$this->project, $mr]))
        ->assertInertia(fn (Assert $page) => $page->where('can.create_rfq', false)->where('linked', null));
});

test('a director without project assignment can view but a stranger cannot', function () {
    $this->makeMr();
    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);

    $this->actingInCompany($stranger, $this->company)->get(route('projects.material-requests.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->director, $this->company)->get(route('projects.material-requests.index', $this->project))->assertOk();
});
