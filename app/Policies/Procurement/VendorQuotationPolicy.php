<?php

namespace App\Policies\Procurement;

use App\Models\Procurement\Rfq;
use App\Models\Procurement\VendorQuotation;
use App\Models\User;
use App\Policies\ProjectPolicy;

class VendorQuotationPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, VendorQuotation $quotation): bool
    {
        $rfq = $quotation->parentRfq();

        return $user->can('vendor_quotations.view') && $this->projects->view($user, $rfq->project);
    }

    public function create(User $user, Rfq $rfq): bool
    {
        return $user->can('vendor_quotations.create') && $rfq->status->acceptsQuotations() && $this->projects->view($user, $rfq->project);
    }

    public function update(User $user, VendorQuotation $quotation): bool
    {
        $rfq = $quotation->parentRfq();

        return $user->can('vendor_quotations.update') && $rfq->status->acceptsQuotations() && $this->projects->view($user, $rfq->project);
    }

    public function delete(User $user, VendorQuotation $quotation): bool
    {
        $rfq = $quotation->parentRfq();

        return $user->can('vendor_quotations.delete') && $rfq->status->acceptsQuotations() && $this->projects->view($user, $rfq->project);
    }
}
