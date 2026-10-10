<?php

namespace App\Services\Uploads;

use App\Contracts\Files\FileSecurityScannerInterface;

/**
 * Used when ClamAV (or another scanner) is not installed. Files are not described as safe.
 */
class NullFileSecurityScanner implements FileSecurityScannerInterface
{
    public function configured(): bool
    {
        return false;
    }

    public function status(): string
    {
        return 'not_configured';
    }
}
