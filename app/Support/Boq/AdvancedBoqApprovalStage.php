<?php

namespace App\Support\Boq;

use App\Enums\Approval\ApprovalActionType;
use App\Enums\Approval\ApprovalStatus;
use App\Enums\Boq\BoqStatus;
use App\Enums\Concerns\HasOptions;
use App\Models\Approval\ApprovalRequest;
use App\Models\Boq\Boq;

/**
 * Derived Advanced BOQ approval stage (outer BoqStatus stays Draft/Submitted/Approved).
 */
enum AdvancedBoqApprovalStage: string
{
    use HasOptions;

    case NotSubmitted = 'not_submitted';
    case TechnicalPending = 'technical_pending';
    case TechnicalChangesRequested = 'technical_changes_requested';
    case CommercialPending = 'commercial_pending';
    case CommercialChangesRequested = 'commercial_changes_requested';
    case FullyApproved = 'fully_approved';
    case Rejected = 'rejected';
    case ChangesRequired = 'changes_required';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Draft',
            self::TechnicalPending => 'Technical Pending',
            self::TechnicalChangesRequested => 'Technical Changes Required',
            self::CommercialPending => 'Commercial Pending',
            self::CommercialChangesRequested => 'Commercial Changes Required',
            self::FullyApproved => 'Approved',
            self::Rejected => 'Rejected',
            self::ChangesRequired => 'Changes Required',
        };
    }

    public static function for(Boq $boq, ?ApprovalRequest $pending = null): self
    {
        if ($boq->status === BoqStatus::Approved) {
            return self::FullyApproved;
        }

        if ($boq->status === BoqStatus::Rejected) {
            return self::Rejected;
        }

        if ($boq->status === BoqStatus::Draft || $boq->status === BoqStatus::Revised) {
            $latest = $boq->relationLoaded('latestApprovalRequest')
                ? $boq->latestApprovalRequest
                : $boq->latestApprovalRequest()->first();

            if ($latest?->status === ApprovalStatus::SentBack) {
                return self::ChangesRequired;
            }

            return self::NotSubmitted;
        }

        if ($boq->status !== BoqStatus::Submitted) {
            return self::NotSubmitted;
        }

        $pending ??= $boq->pendingApprovalRequest();
        $atCommercial = $boq->technical_approved_at !== null
            || ($pending !== null && $pending->isLastLevel() && count($pending->steps ?? []) > 1);

        $hasChanges = false;
        if ($pending !== null) {
            $hasChanges = $pending->relationLoaded('actions')
                ? $pending->actions->contains(
                    fn ($a) => $a->action === ApprovalActionType::ChangesRequested
                        && (int) $a->level === (int) $pending->current_level
                )
                : $pending->actions()
                    ->where('level', $pending->current_level)
                    ->where('action', ApprovalActionType::ChangesRequested)
                    ->exists();
        }

        if ($atCommercial) {
            return $hasChanges ? self::CommercialChangesRequested : self::CommercialPending;
        }

        return $hasChanges ? self::TechnicalChangesRequested : self::TechnicalPending;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function filterSelectOptions(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            [
                self::TechnicalPending,
                self::CommercialPending,
                self::FullyApproved,
                self::ChangesRequired,
                self::TechnicalChangesRequested,
                self::CommercialChangesRequested,
            ],
        );
    }
}
