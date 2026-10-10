<?php

namespace App\Services\Files\Cad;

/**
 * A browser-safe file written by a local converter. The path is temporary and private.
 */
final class CadPreviewResult
{
    public function __construct(
        public readonly string $path,
        public readonly string $format,
    ) {}
}
