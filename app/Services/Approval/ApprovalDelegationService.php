<?php

namespace App\Services\Approval;

use App\Enums\Approval\ApproverType;
use App\Models\Approval\ApprovalDelegation;
use App\Models\Approval\ApprovalRequest;
use App\Models\Approval\ApprovalStep;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalDelegationService
{
    public function __construct(private readonly ApproverResolver $resolver) {}

    /**
     * @param  array{delegate_id: int, starts_at: string, ends_at: string, document_types?: list<string>|null, reason?: string|null}  $data
     */
    public function create(User $delegator, array $data): ApprovalDelegation
    {
        return DB::transaction(function () use ($delegator, $data) {
            $delegateId = (int) $data['delegate_id'];
            if ($delegateId === $delegator->id) {
                throw ValidationException::withMessages(['delegate_id' => 'You cannot delegate approval authority to yourself.']);
            }

            $documentTypes = $data['document_types'] ?? null;
            $this->assertDelegatorCanDelegate($delegator, $documentTypes);

            return ApprovalDelegation::query()->create([
                'delegator_id' => $delegator->id,
                'delegate_id' => $delegateId,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'document_types' => $documentTypes,
                'reason' => $data['reason'] ?? null,
                'is_active' => true,
                'created_by' => $delegator->id,
            ]);
        });
    }

    public function revoke(ApprovalDelegation $delegation, User $user): void
    {
        if (! $user->can('admin.workflows.manage') && (int) $delegation->delegator_id !== (int) $user->id) {
            throw ValidationException::withMessages(['delegation' => 'You can only revoke delegations you created.']);
        }

        $delegation->forceFill(['is_active' => false])->save();
    }

    /**
     * Active delegations where the given user is the delegate and may act for a delegator on this request.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function actingFor(User $delegate, ApprovalRequest $request): Collection
    {
        $documentType = $request->approvable?->approvalDocumentType();
        if ($documentType === null) {
            return collect();
        }

        return ApprovalDelegation::query()
            ->where('delegate_id', $delegate->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get()
            ->filter(fn (ApprovalDelegation $d) => $d->coversDocumentType($documentType));
    }

    /**
     * Delegator id if this user may act only via delegation (not a direct approver).
     */
    public function delegatorCovering(User $actor, ApprovalRequest $request): ?int
    {
        $approvers = $this->resolver->forLevel($request, $request->current_level);
        if ($approvers->contains('id', $actor->id)) {
            return null;
        }

        foreach ($this->actingFor($actor, $request) as $delegation) {
            if ($approvers->contains('id', $delegation->delegator_id)) {
                return $delegation->delegator_id;
            }
        }

        return null;
    }

    /**
     * @param  list<string>|null  $documentTypes
     */
    private function assertDelegatorCanDelegate(User $delegator, ?array $documentTypes): void
    {
        $companyId = app(CurrentCompany::class)->id();
        $types = $documentTypes ?? ApprovalWorkflow::query()
            ->whereNull('project_id')
            ->where('is_active', true)
            ->pluck('document_type')
            ->unique()
            ->all();

        foreach ($types as $type) {
            if (! $this->delegatorEligibleForType($delegator, $type, $companyId)) {
                throw ValidationException::withMessages([
                    'document_types' => "You are not configured as an approver for {$type}, so you cannot delegate it.",
                ]);
            }
        }
    }

    private function delegatorEligibleForType(User $delegator, string $documentType, int $companyId): bool
    {
        $workflows = ApprovalWorkflow::query()
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->whereNull('project_id')
            ->with('steps')
            ->get();

        foreach ($workflows as $workflow) {
            foreach ($workflow->steps as $step) {
                if ($this->delegatorMatchesStep($delegator, $step, $companyId)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function delegatorMatchesStep(User $delegator, ApprovalStep $step, int $companyId): bool
    {
        return match ($step->approver_type) {
            ApproverType::User => (int) $step->user_id === (int) $delegator->id,
            ApproverType::Role => $delegator->roles()
                ->where('roles.id', $step->role_id)
                ->where('roles.'.config('permission.column_names.team_foreign_key'), $companyId)
                ->exists(),
            ApproverType::ProjectRole => $delegator->projectMemberships()
                ->where('project_role', $step->project_role)
                ->where('is_active', true)
                ->exists(),
            default => false,
        };
    }
}
