<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityMeter;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class ElectricityMeterPolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, ElectricityMeter $meter): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.manage_connections');
    }

    public function update(User $user, ElectricityMeter $meter): bool
    {
        return $user->can('utilities.electricity.manage_connections');
    }

    public function delete(User $user, ElectricityMeter $meter): bool
    {
        return $user->can('utilities.electricity.manage_connections')
            && ! $meter->readings()->where('status', '!=', 'cancelled')->exists();
    }
}
