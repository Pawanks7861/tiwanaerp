<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum BoqStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revised = 'revised';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft, self::Rejected => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::Draft],
            self::Approved => [self::Revised],
            self::Revised => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Lines and header may only change while the BOQ is being prepared or corrected. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
