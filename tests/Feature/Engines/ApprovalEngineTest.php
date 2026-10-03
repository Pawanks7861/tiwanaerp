<?php

use App\Enums\Approval\ApprovalStatus;
use App\Exceptions\ApprovalException;
use App\Models\Approval\ApprovalRequest;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Projects\Project;
use App\Notifications\Approval\ApprovalDecisionNotification;
use App\Notifications\Approval\ApprovalRequestedNotification;
use App\Services\Approval\ApprovalService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TestDocument;

beforeEach(function () {
    TestDocument::createTable();

    $this->company = $this->createCompany();
    $this->pm = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER);
    $this->purchase = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);

    $this->project = $this->inCompany($this->company, fn () => Project::factory()->create(['project_manager_id' => $this->pm->id]));
    $this->document = approvalDocument($this);
});

/**
 * Seeded "material_request" workflow: level 1 = the project's manager, level 2 = Purchase Manager role.
 */
function approvalSubmit($test, ?TestDocument $document = null, $submitter = null): ApprovalRequest
{
    return $test->inCompany($test->company, fn () => app(ApprovalService::class)
        ->submit($document ?? $test->document, $submitter ?? $test->engineer));
}

function approvalDocument($test, string $title = 'MR first'): TestDocument
{
    return $test->inCompany($test->company, fn () => TestDocument::query()->create([
        'project_id' => $test->project->id,
        'title' => $title,
        'amount' => '50000.00',
    ]));
}

function approvalEngine(): ApprovalService
{
    return app(ApprovalService::class);
}

test('a document moves through every approval level and notifies the right people', function () {
    Notification::fake();

    $request = approvalSubmit($this);

    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->current_level)->toBe(1)
        ->and($this->document->fresh()->status)->toBe('pending');
    Notification::assertSentTo($this->pm, ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($this->purchase, ApprovalRequestedNotification::class);

    $this->inCompany($this->company, function () use ($request) {
        expect(approvalEngine()->canAct($request, $this->purchase))->toBeFalse()
            ->and(approvalEngine()->canAct($request, $this->pm))->toBeTrue();

        $request = approvalEngine()->approve($request, $this->pm, 'Quantities checked');
        expect($request->current_level)->toBe(2)->and($request->status)->toBe(ApprovalStatus::Pending);
        Notification::assertSentTo($this->purchase, ApprovalRequestedNotification::class);

        $request = approvalEngine()->approve($request, $this->purchase);
        expect($request->status)->toBe(ApprovalStatus::Approved)
            ->and($request->completed_at)->not->toBeNull()
            ->and($request->actions()->pluck('action')->map->value->all())->toBe(['submitted', 'approved', 'approved']);
    });

    expect($this->document->fresh()->status)->toBe('approved');
    Notification::assertSentTo($this->engineer, ApprovalDecisionNotification::class);
});

test('only approvers of the current level can act', function () {
    $request = approvalSubmit($this);

    $this->inCompany($this->company, fn () => approvalEngine()->approve($request, $this->purchase));
})->throws(ApprovalException::class, 'You are not an approver for the current level of this document.');

test('a submitter cannot approve their own document', function () {
    $request = approvalSubmit($this, submitter: $this->pm);

    $this->inCompany($this->company, function () use ($request) {
        expect(approvalEngine()->canAct($request, $this->pm))->toBeFalse();
        approvalEngine()->approve($request, $this->pm);
    });
})->throws(ApprovalException::class);

test('a document cannot be submitted twice while pending', function () {
    approvalSubmit($this);
    approvalSubmit($this);
})->throws(ApprovalException::class, 'This document is already awaiting approval.');

test('rejecting requires a reason and notifies the submitter', function () {
    Notification::fake();
    $request = approvalSubmit($this);

    $this->inCompany($this->company, function () use ($request) {
        expect(fn () => approvalEngine()->reject($request, $this->pm, '  '))->toThrow(ApprovalException::class, 'Please give a reason.');

        $rejected = approvalEngine()->reject($request, $this->pm, 'Wrong grade of cement');
        expect($rejected->status)->toBe(ApprovalStatus::Rejected);
    });

    expect($this->document->fresh()->status)->toBe('rejected');
    Notification::assertSentTo($this->engineer, ApprovalDecisionNotification::class);
});

test('a sent-back document can be corrected and resubmitted', function () {
    $request = approvalSubmit($this);
    $this->inCompany($this->company, fn () => approvalEngine()->sendBack($request, $this->pm, 'Add the delivery date'));

    expect($this->document->fresh()->status)->toBe('draft');

    $again = approvalSubmit($this, $this->document->fresh());
    expect($again->id)->not->toBe($request->id)->and($again->status)->toBe(ApprovalStatus::Pending);
});

test('only the submitter can withdraw a request', function () {
    $request = approvalSubmit($this);

    $this->inCompany($this->company, function () use ($request) {
        expect(fn () => approvalEngine()->cancel($request, $this->pm))->toThrow(ApprovalException::class);

        expect(approvalEngine()->cancel($request, $this->engineer)->status)->toBe(ApprovalStatus::Cancelled);
    });
});

test('a project-specific workflow takes precedence over the company default', function () {
    $this->inCompany($this->company, function () {
        $workflow = ApprovalWorkflow::query()->create([
            'document_type' => 'material_request',
            'project_id' => $this->project->id,
            'name' => 'Tower project MR approval',
            'is_active' => true,
        ]);
        $workflow->steps()->create(['level' => 1, 'mode' => 'any', 'approver_type' => 'user', 'user_id' => $this->purchase->id, 'name' => 'Purchase head']);

        expect(approvalEngine()->resolveWorkflow($this->document)->id)->toBe($workflow->id);
    });

    $request = approvalSubmit($this);
    $this->inCompany($this->company, fn () => expect(approvalEngine()->canAct($request, $this->purchase))->toBeTrue()
        ->and(approvalEngine()->canAct($request, $this->pm))->toBeFalse());
});

test('approvers act through the inbox; rejecting over HTTP requires comments', function () {
    $first = approvalSubmit($this);
    approvalSubmit($this, approvalDocument($this, 'MR second'));

    $this->actingInCompany($this->pm, $this->company)
        ->get(route('approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Approvals/Index', false)->has('toAct', 2));

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('approvals.reject', $first))
        ->assertSessionHasErrors('comments');

    $this->actingInCompany($this->pm, $this->company)
        ->post(route('approvals.approve', $first), ['comments' => 'OK'])
        ->assertSessionHasNoErrors();

    expect($first->fresh()->current_level)->toBe(2);
});

test('approval requests of another company cannot be reached', function () {
    $request = approvalSubmit($this);

    $other = $this->createCompany();
    $otherAdmin = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);

    $this->actingInCompany($otherAdmin, $other)
        ->post(route('approvals.approve', $request->id))
        ->assertNotFound();

    expect($request->fresh()->current_level)->toBe(1);
});
