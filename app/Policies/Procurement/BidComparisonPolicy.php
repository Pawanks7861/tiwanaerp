<?php

namespace App\Policies\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\RfqStatus;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Rfq;
use App\Models\User;
use App\Policies\ProjectPolicy;

class BidComparisonPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function view(User $user, Rfq $rfq): bool
    {
        return $user->can('bid_comparison.view') && $this->projects->view($user, $rfq->project);
    }

    public function create(User $user, Rfq $rfq): bool
    {
        return $user->can('bid_comparison.create') && $rfq->status === RfqStatus::QuotesReceived && $this->projects->view($user, $rfq->project);
    }

    public function update(User $user, BidComparison $comparison): bool
    {
        return $user->can('bid_comparison.create') && $comparison->isEditable() && $this->projects->view($user, $comparison->rfq->project);
    }

    public function approve(User $user, BidComparison $comparison): bool
    {
        return $user->can('bid_comparison.approve') && $comparison->status === BidComparisonStatus::Submitted
            && $this->projects->view($user, $comparison->rfq->project);
    }
}
