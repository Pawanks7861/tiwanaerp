<?php

namespace App\Support\Finance;

/**
 * Normalizes vendor invoice numbers for duplicate detection while keeping the original entry unchanged.
 */
final class VendorInvoiceNormalizer
{
    public static function normalize(?string $invoiceNo): ?string
    {
        if ($invoiceNo === null) {
            return null;
        }

        $value = mb_strtoupper(trim($invoiceNo));
        if ($value === '') {
            return null;
        }

        $value = (string) preg_replace('/[\s\-.\/]+/u', '', $value);

        return $value === '' ? null : mb_substr($value, 0, 60);
    }
}
