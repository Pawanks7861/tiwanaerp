<?php

namespace App\Contracts\Files;

/**
 * Hook for a malware scanner. The default implementation is not a scanner.
 */
interface FileSecurityScannerInterface
{
    public function configured(): bool;

    /**
     * not_configured, pending, safe, blocked, or scan_failed.
     * "safe" is only returned after a real scanner has inspected the file.
     */
    public function status(): string;
}
