<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Raised for invalid approval actions; rendered as a validation error so the UI shows the reason.
 */
class ApprovalException extends ValidationException
{
    public static function because(string $message): self
    {
        return static::withMessages(['approval' => $message]);
    }
}
