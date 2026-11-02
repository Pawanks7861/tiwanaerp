<?php

namespace App\Enums\Imports;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case Ready = 'ready';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Validating => 'Validating',
            self::Ready => 'Ready for confirmation',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with errors',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::CompletedWithErrors,
            self::Failed,
            self::Cancelled,
        ], true);
    }
}
