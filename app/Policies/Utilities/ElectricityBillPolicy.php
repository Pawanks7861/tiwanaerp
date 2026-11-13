<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityBill;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class ElectricityBillPolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, ElectricityBill $bill): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.create_bill');
    }

    public function update(User $user, ElectricityBill $bill): bool
    {
        return $user->can('utilities.electricity.create_bill') && $bill->isEditable();
    }

    public function delete(User $user, ElectricityBill $bill): bool
    {
        return $user->can('utilities.electricity.create_bill') && $bill->isEditable();
    }

    public function review(User $user, ElectricityBill $bill): bool
    {
        return $user->can('utilities.electricity.review');
    }

    public function approve(User $user, ElectricityBill $bill): bool
    {
        return $user->can('utilities.electricity.approve');
    }
}
