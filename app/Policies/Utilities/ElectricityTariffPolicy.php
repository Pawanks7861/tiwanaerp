<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityTariff;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class ElectricityTariffPolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, ElectricityTariff $tariff): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.manage_tariffs');
    }

    public function update(User $user, ElectricityTariff $tariff): bool
    {
        return $user->can('utilities.electricity.manage_tariffs');
    }

    public function delete(User $user, ElectricityTariff $tariff): bool
    {
        return $user->can('utilities.electricity.manage_tariffs');
    }
}
