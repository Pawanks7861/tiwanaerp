<?php

namespace App\Http\Controllers\Labour;

use App\Models\User;

/**
 * Wage and labour-cost fields for mobile/API payloads. Mirrors inventory valuation gating.
 */
class LabourPresenter
{
    public static function seesWages(User $user): bool
    {
        return $user->can('labour.manage_payments')
            || $user->can('labour.approve_attendance')
            || $user->can('boq.view_costs');
    }

    public static function seesAttendanceAmounts(User $user): bool
    {
        return $user->can('labour.manage_payments') || $user->can('labour.approve_attendance');
    }

    public static function seesSubcontractorBank(User $user): bool
    {
        return $user->can('masters.subcontractors.view') && $user->can('payments.view');
    }
}
