<?php

namespace App\Policies\Finance;

use App\Models\Finance\ClientInvoice;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

/**
 * billing.* permission AND project access. Certification runs through the engine
 * (client_invoice: PM → Director); the final approver additionally needs billing.certify.
 * Quantities above the executed / BOQ quantity need billing.override_qty.
 */
class ClientInvoicePolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('billing.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, ClientInvoice $invoice): bool
    {
        return $user->can('billing.view') && $this->projects->view($user, $invoice->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('billing.create') && $this->projects->view($user, $project);
    }

    public function update(User $user, ClientInvoice $invoice): bool
    {
        return $user->can('billing.update') && $invoice->isEditable() && $this->projects->view($user, $invoice->project);
    }

    public function delete(User $user, ClientInvoice $invoice): bool
    {
        return $user->can('billing.delete') && $invoice->isEditable() && $this->projects->view($user, $invoice->project);
    }

    public function submit(User $user, ClientInvoice $invoice): bool
    {
        return $user->can('billing.submit') && $invoice->isEditable() && $this->projects->view($user, $invoice->project);
    }

    public function export(User $user, ClientInvoice $invoice): bool
    {
        return $user->can('billing.export') && $this->view($user, $invoice);
    }
}
