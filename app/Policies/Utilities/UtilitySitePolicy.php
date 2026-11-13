<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\UtilitySite;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class UtilitySitePolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, UtilitySite $site): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.manage_sites');
    }

    public function update(User $user, UtilitySite $site): bool
    {
        return $user->can('utilities.electricity.manage_sites');
    }

    public function delete(User $user, UtilitySite $site): bool
    {
        return $user->can('utilities.electricity.manage_sites')
            && ! $site->connections()->exists();
    }
}
