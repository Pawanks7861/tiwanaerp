<?php

namespace App\Policies\Crm;

use App\Enums\Crm\QuotationStatus;
use App\Models\Crm\Quotation;
use App\Models\User;

/**
 * crm.quotations.*: create / update drafts and send them, approve records the client's decision
 * (accept / reject / expire), convert turns an accepted quotation into a project.
 */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('crm.quotations.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.update') && $quotation->status === QuotationStatus::Draft;
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.delete') && $quotation->status === QuotationStatus::Draft && $quotation->revision === 0;
    }

    public function send(User $user, Quotation $quotation): bool
    {
        return $this->update($user, $quotation);
    }

    public function decide(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.approve') && $quotation->status === QuotationStatus::Sent;
    }

    public function revise(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.create') && $quotation->status->isRevisable();
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $user->can('crm.quotations.convert') && $quotation->status === QuotationStatus::Accepted
            && $quotation->converted_project_id === null;
    }
}
