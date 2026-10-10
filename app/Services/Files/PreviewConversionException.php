<?php

namespace App\Services\Files;

use RuntimeException;

/**
 * A conversion failure whose message is safe to store and show. It never includes a path or shell output.
 */
class PreviewConversionException extends RuntimeException
{
    public const UNAVAILABLE = 'Converter unavailable';

    public const UNSUPPORTED = 'Unsupported format';

    public const CORRUPTED = 'File corrupted';

    public const FAILED = 'Conversion failed';

    public function __construct(public readonly string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
