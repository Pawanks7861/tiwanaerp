<?php

namespace App\Services\Approval;

use App\Contracts\Approvable;
use App\Enums\Approval\ApprovalActionType;
use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApprovalStatus;
use App\Events\Approval\ApprovalCompleted;
use App\Events\Approval\ApprovalRejected;
use App\Events\Approval\ApprovalRequested;
use App\Exceptions\ApprovalException;
use App\Models\Approval\ApprovalRequest;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generic approval engine shared by every approvable document (architecture J.1).
 * Every state change runs in a transaction with the request row locked, so two approvers
 * acting at the same moment cannot both advance the same level.
 */
class ApprovalService
{
    public function __construct(private readonly ApproverResolver $resolver) {}

    public function submit(Approvable&Model $document, User $submitter): ApprovalRequest
    {
        $request = DB::transaction(function () use ($document, $submitter) {
            $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->first();

            $pendingExists = ApprovalRequest::query()
                ->where('approvable_type', $document->getMorphClass())
                ->where('approvable_id', $document->getKey())
                ->where('status', ApprovalStatus::Pending)
                ->exists();
            if ($pendingExists) {
                throw ApprovalException::because('This document is already awaiting approval.');
            }

            $workflow = $this->resolveWorkflow($document)
                ?? throw ApprovalException::because('No approval workflow is configured for this document type.');

            $steps = $workflow->steps->map->toSnapshot()->values()->all();
            if ($steps === []) {
                throw ApprovalException::because('The approval workflow has no steps.');
            }

            $request = ApprovalRequest::create([
                'approvable_type' => $document->getMorphClass(),
                'approvable_id' => $document->getKey(),
                'approval_workflow_id' => $workflow->id,
                'steps' => $steps,
                'current_level' => (int) $steps[0]['level'],
                'status' => ApprovalStatus::Pending,
                'submitted_by' => $submitter->id,
                'submitted_at' => now(),
            ]);

            $this->recordAction($request, $submitter, ApprovalActionType::Submitted);
            $request->setRelation('approvable', $document);
            $document->onApprovalSubmitted();

            return $request;
        });

        ApprovalRequested::dispatch($request);

        return $request;
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $comments = null): ApprovalRequest
    {
        $event = null;

        $request = DB::transaction(function () use ($request, $approver, $comments, &$event) {
            $request = $this->lockPending($request);
            $this->assertCanAct($request, $approver);

            $level = $request->current_level;
            $this->recordAction($request, $approver, ApprovalActionType::Approved, $comments);

            if (! $this->isLevelComplete($request, $level)) {
                return $request;
            }

            if ($request->isLastLevel()) {
                $request->forceFill(['status' => ApprovalStatus::Approved, 'completed_at' => now()])->save();
                $request->approvable->onApprovalCompleted();
                $event = new ApprovalCompleted($request);
            } else {
                $nextLevel = (int) collect($request->steps)->pluck('level')->filter(fn ($l) => $l > $level)->min();
                $request->forceFill(['current_level' => $nextLevel])->save();
                $event = new ApprovalRequested($request);
            }

            return $request;
        });

        if ($event) {
            event($event);
        }

        return $request;
    }

    public function reject(ApprovalRequest $request, User $approver, string $comments): ApprovalRequest
    {
        return $this->close($request, $approver, $comments, ApprovalStatus::Rejected, ApprovalActionType::Rejected);
    }

    /**
     * Returns the document to its author for correction and resubmission.
     */
    public function sendBack(ApprovalRequest $request, User $approver, string $comments): ApprovalRequest
    {
        return $this->close($request, $approver, $comments, ApprovalStatus::SentBack, ApprovalActionType::SentBack);
    }

    /**
     * Withdraw a pending request. Only the submitter may cancel.
     */
    public function cancel(ApprovalRequest $request, User $user, ?string $comments = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $user, $comments) {
            $request = $this->lockPending($request);

            if ($request->submitted_by !== $user->id) {
                throw ApprovalException::because('Only the submitter can cancel this approval request.');
            }

            $this->recordAction($request, $user, ApprovalActionType::Cancelled, $comments);
            $request->forceFill(['status' => ApprovalStatus::Cancelled, 'completed_at' => now()])->save();
            $request->approvable->onApprovalCancelled();

            return $request;
        });
    }

    public function canAct(ApprovalRequest $request, User $user): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        if ($request->submitted_by === $user->id && ! config('approvals.allow_self_approval')) {
            return false;
        }

        $alreadyActed = $request->actions()
            ->where('level', $request->current_level)
            ->where('user_id', $user->id)
            ->where('action', ApprovalActionType::Approved)
            ->exists();

        return ! $alreadyActed && $this->resolver->forLevel($request, $request->current_level)->contains('id', $user->id);
    }

    /**
     * @return Collection<int, User>
     */
    public function approversForCurrentLevel(ApprovalRequest $request): Collection
    {
        return $this->resolver->forLevel($request, $request->current_level);
    }

    /**
     * Pending requests in the current company that the user can act on now.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function pendingFor(User $user, int $limit = 200): Collection
    {
        return ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->with(['approvable', 'submitter:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->filter(fn (ApprovalRequest $r) => $r->approvable !== null && $this->canAct($r, $user))
            ->values();
    }

    public function resolveWorkflow(Approvable&Model $document): ?ApprovalWorkflow
    {
        $amount = $document->approvalAmount();
        $projectId = $document->approvalProjectId();

        return ApprovalWorkflow::query()
            ->where('document_type', $document->approvalDocumentType())
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->when($projectId, fn ($q) => $q->orWhere('project_id', $projectId)))
            ->with('steps')
            ->get()
            ->filter(function (ApprovalWorkflow $wf) use ($amount) {
                if ($amount === null) {
                    return true;
                }
                $value = Decimal::of($amount);

                return ($wf->min_amount === null || $value->greaterThanOrEqual($wf->min_amount))
                    && ($wf->max_amount === null || $value->lessThanOrEqual($wf->max_amount));
            })
            // Project-specific workflows win over company-wide ones; then the narrowest (highest minimum) range.
            ->sort(function (ApprovalWorkflow $a, ApprovalWorkflow $b) {
                $byProject = ($b->project_id ? 1 : 0) <=> ($a->project_id ? 1 : 0);

                return $byProject !== 0 ? $byProject : Decimal::of($b->min_amount)->compareTo($a->min_amount);
            })
            ->first();
    }

    private function close(ApprovalRequest $request, User $approver, string $comments, ApprovalStatus $status, ApprovalActionType $action): ApprovalRequest
    {
        if (trim($comments) === '') {
            throw ApprovalException::because('Please give a reason.');
        }

        $request = DB::transaction(function () use ($request, $approver, $comments, $status, $action) {
            $request = $this->lockPending($request);
            $this->assertCanAct($request, $approver);

            $this->recordAction($request, $approver, $action, $comments);
            $request->forceFill(['status' => $status, 'completed_at' => now()])->save();

            $status === ApprovalStatus::Rejected
                ? $request->approvable->onApprovalRejected()
                : $request->approvable->onApprovalSentBack();

            return $request;
        });

        ApprovalRejected::dispatch($request, $comments);

        return $request;
    }

    private function lockPending(ApprovalRequest $request): ApprovalRequest
    {
        $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw ApprovalException::because('This approval request is no longer pending.');
        }

        return $locked;
    }

    private function assertCanAct(ApprovalRequest $request, User $user): void
    {
        if (! $this->canAct($request, $user)) {
            throw ApprovalException::because('You are not an approver for the current level of this document.');
        }
    }

    private function isLevelComplete(ApprovalRequest $request, int $level): bool
    {
        $step = $request->stepForLevel($level);
        if (ApprovalMode::from($step['mode']) === ApprovalMode::Any) {
            return true;
        }

        $approvedBy = $request->actions()
            ->where('level', $level)
            ->where('action', ApprovalActionType::Approved)
            ->pluck('user_id');

        $required = $this->resolver->forLevel($request, $level)
            ->reject(fn (User $u) => $u->id === $request->submitted_by && ! config('approvals.allow_self_approval'))
            ->pluck('id');

        return $required->diff($approvedBy)->isEmpty();
    }

    private function recordAction(ApprovalRequest $request, User $user, ApprovalActionType $action, ?string $comments = null): void
    {
        $request->actions()->create([
            'level' => $request->current_level,
            'user_id' => $user->id,
            'action' => $action,
            'comments' => $comments,
            'acted_at' => now(),
        ]);
    }
}
