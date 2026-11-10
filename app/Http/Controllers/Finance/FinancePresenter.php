<?php

namespace App\Http\Controllers\Finance;

use App\Models\User;

/**
 * Mobile finance redaction: amounts require the module view permission or reports.view_financial.
 * Payment list resources never expose bank account details — only payment_reference on show when permitted.
 */
class FinancePresenter
{
    public static function seesBilling(User $user): bool
    {
        return $user->can('billing.view') || $user->can('reports.view_financial');
    }

    public static function seesPayments(User $user): bool
    {
        return $user->can('payments.view') || $user->can('reports.view_financial');
    }

    public static function seesVendorBills(User $user): bool
    {
        return $user->can('vendor_bills.view') || $user->can('reports.view_financial');
    }

    public static function seesExpenses(User $user): bool
    {
        return $user->can('expenses.view') || $user->can('reports.view_financial');
    }

    public static function seesAnyFinance(User $user): bool
    {
        return self::seesBilling($user)
            || self::seesPayments($user)
            || self::seesVendorBills($user)
            || self::seesExpenses($user)
            || $user->can('approvals.view');
    }
}
