<?php

namespace App\Policies\Inventory;

use App\Enums\Inventory\StockTransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\Projects\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

class StockTransferPolicy
{
    public function __construct(private readonly ProjectPolicy $projects) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $project);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.view') && $this->projects->view($user, $transfer->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->can('inventory.transfer') && $this->projects->view($user, $project);
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $this->transfers($user, $transfer) && $transfer->isEditable();
    }

    public function delete(User $user, StockTransfer $transfer): bool
    {
        return $this->update($user, $transfer);
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $this->update($user, $transfer);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->transfers($user, $transfer) && $transfer->status->isInTransit();
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->transfers($user, $transfer) && $transfer->status === StockTransferStatus::Dispatched;
    }

    public function closeShort(User $user, StockTransfer $transfer): bool
    {
        return $this->transfers($user, $transfer) && $transfer->status === StockTransferStatus::PartiallyReceived;
    }

    private function transfers(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.transfer') && $this->projects->view($user, $transfer->project);
    }
}
