<?php

namespace App\Policies\Utilities\Concerns;

use App\Models\User;

trait ElectricityPermissions
{
    protected function canViewElectricity(User $user): bool
    {
        return $user->can('utilities.electricity.view') || $user->can('utilities.view');
    }
}
