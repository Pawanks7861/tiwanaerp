<?php

namespace App\Services\Files;

/**
 * Local office and CAD conversion. Implementations must not send bytes to a public service,
 * and must not build a shell command from a user-supplied filename.
 */
interface PreviewConverter
{
    public function officeAvailable(): bool;

    public function cadAvailable(): bool;

    /**
     * Write a PDF into $directory and return its absolute path.
     */
    public function toPdf(string $sourceAbsolute, string $directory): string;

    /**
     * Write an SVG into $directory and return its absolute path.
     */
    public function toSvg(string $sourceAbsolute, string $directory): string;
}
