<?php

namespace App\Http\Controllers\Equipment;

use App\Models\User;

/**
 * Equipment charge-out and fuel cost fields for mobile/API payloads.
 */
class EquipmentPresenter
{
    public static function seesCosts(User $user): bool
    {
        return $user->can('equipment.assign')
            || $user->can('boq.view_costs')
            || $user->can('labour.manage_payments');
    }
}
