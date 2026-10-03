<?php

namespace App\Http\Controllers\Core;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval\ApprovalRequest;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approvals inbox: requests the user can act on now, plus their own pending submissions.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        Gate::authorize('approvals.view');

        $user = $request->user();

        $toAct = $this->approvals->pendingFor($user)->map(fn (ApprovalRequest $r) => $this->row($r));

        $submitted = ApprovalRequest::query()
            ->where('submitted_by', $user->id)
            ->where('status', ApprovalStatus::Pending)
            ->with('approvable')
            ->latest('id')
            ->limit(100)
            ->get()
            ->filter(fn (ApprovalRequest $r) => $r->approvable !== null)
            ->map(fn (ApprovalRequest $r) => $this->row($r))
            ->values();

        return Inertia::render('Approvals/Index', [
            'toAct' => $toAct->all(),
            'submitted' => $submitted->all(),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comments = $request->validate(['comments' => ['nullable', 'string', 'max:2000']])['comments'] ?? null;
        $this->approvals->approve($approvalRequest, $request->user(), $comments);

        return back()->with('success', 'Approved.');
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comments = $request->validate(['comments' => ['required', 'string', 'max:2000']])['comments'];
        $this->approvals->reject($approvalRequest, $request->user(), $comments);

        return back()->with('success', 'Rejected.');
    }

    public function sendBack(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comments = $request->validate(['comments' => ['required', 'string', 'max:2000']])['comments'];
        $this->approvals->sendBack($approvalRequest, $request->user(), $comments);

        return back()->with('success', 'Sent back for changes.');
    }

    public function cancel(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comments = $request->validate(['comments' => ['nullable', 'string', 'max:2000']])['comments'] ?? null;
        $this->approvals->cancel($approvalRequest, $request->user(), $comments);

        return back()->with('success', 'Approval request withdrawn.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ApprovalRequest $request): array
    {
        /** @var Approvable $document */
        $document = $request->approvable;
        $step = $request->currentStep();

        return [
            'id' => $request->id,
            'title' => $document->approvalTitle(),
            'url' => method_exists($document, 'approvalUrl') ? $document->approvalUrl() : null,
            'document_type' => $document->approvalDocumentType(),
            'amount' => $document->approvalAmount(),
            'level' => $request->current_level,
            'levels' => count($request->steps ?? []),
            'step_name' => $step['name'] ?? null,
            'submitted_by' => $request->relationLoaded('submitter') ? $request->submitter?->name : null,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
        ];
    }
}
