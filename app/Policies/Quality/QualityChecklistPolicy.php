<?php

namespace App\Policies\Quality;

use App\Models\Quality\QualityChecklist;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

/**
 * Checklist templates are company masters: quality.view to read; maintaining them is part of the
 * inspector's role (quality.perform_inspection), so no extra permission is introduced.
 */
class QualityChecklistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quality.view');
    }

    public function view(User $user, QualityChecklist $checklist): bool
    {
        return $this->owns($checklist) && $user->can('quality.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quality.perform_inspection');
    }

    public function update(User $user, QualityChecklist $checklist): bool
    {
        return $this->owns($checklist) && $user->can('quality.perform_inspection');
    }

    public function delete(User $user, QualityChecklist $checklist): bool
    {
        return $this->owns($checklist) && $user->can('quality.perform_inspection') && ! $checklist->isInUse();
    }

    private function owns(QualityChecklist $checklist): bool
    {
        return (int) $checklist->company_id === app(CurrentCompany::class)->id();
    }
}
