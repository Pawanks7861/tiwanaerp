<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityConnection;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class ElectricityConnectionPolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, ElectricityConnection $connection): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.manage_connections');
    }

    public function update(User $user, ElectricityConnection $connection): bool
    {
        return $user->can('utilities.electricity.manage_connections');
    }

    public function delete(User $user, ElectricityConnection $connection): bool
    {
        return $user->can('utilities.electricity.manage_connections');
    }
}
