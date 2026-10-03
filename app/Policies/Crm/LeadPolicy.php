<?php

namespace App\Policies\Crm;

use App\Models\Crm\Lead;
use App\Models\User;

/**
 * crm.leads.* within the current company (tenant scoping keeps other companies' leads out).
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crm.leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.update');
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.delete') && ! $lead->quotations()->exists();
    }
}
