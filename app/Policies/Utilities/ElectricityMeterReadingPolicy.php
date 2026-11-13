<?php

namespace App\Policies\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityMeterReading;
use App\Policies\Utilities\Concerns\ElectricityPermissions;

class ElectricityMeterReadingPolicy
{
    use ElectricityPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canViewElectricity($user);
    }

    public function view(User $user, ElectricityMeterReading $reading): bool
    {
        return $this->canViewElectricity($user);
    }

    public function create(User $user): bool
    {
        return $user->can('utilities.electricity.enter_readings');
    }

    public function update(User $user, ElectricityMeterReading $reading): bool
    {
        return $user->can('utilities.electricity.enter_readings') && $reading->isEditable();
    }

    public function delete(User $user, ElectricityMeterReading $reading): bool
    {
        return $user->can('utilities.electricity.enter_readings')
            && $reading->isEditable()
            && ! $reading->bill()->exists();
    }

    public function confirm(User $user, ElectricityMeterReading $reading): bool
    {
        return $user->can('utilities.electricity.enter_readings') && $reading->isEditable();
    }
}
