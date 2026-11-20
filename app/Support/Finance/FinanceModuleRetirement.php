<?php

namespace App\Support\Finance;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft-retire Client RA bills, Vendor Bills, Expenses, Petty Cash, and Payments.
 * Tables and historical rows remain; new writes are blocked. Subcontract bills stay active.
 */
final class FinanceModuleRetirement
{
    public const MESSAGE = 'This finance module is retired. Procurement value comes from approved GRNs. Use Subcontract Bills for subcontractor AP.';

    /**
     * Abort create / store / update / submit / process style writes.
     */
    public static function denyWrite(bool $asJson = false): never
    {
        if ($asJson) {
            throw new HttpResponseException(response()->json([
                'message' => self::MESSAGE,
            ], Response::HTTP_FORBIDDEN));
        }

        throw ValidationException::withMessages([
            'finance' => self::MESSAGE,
        ]);
    }

    public static function isRetiredPermission(string $permission): bool
    {
        return in_array($permission, [
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.import',
            'petty_cash.view', 'petty_cash.create', 'petty_cash.update',
            'billing.view', 'billing.create', 'billing.update', 'billing.submit', 'billing.export',
            'vendor_bills.view', 'vendor_bills.create', 'vendor_bills.update', 'vendor_bills.submit',
            'payments.view', 'payments.record', 'payments.approve',
        ], true);
    }
}
