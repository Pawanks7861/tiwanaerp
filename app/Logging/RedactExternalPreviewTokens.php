<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes the secret segment of an external preview URL from log records.
 */
class RedactExternalPreviewTokens
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new class implements ProcessorInterface
        {
            public function __invoke(LogRecord $record): LogRecord
            {
                return $record->with(
                    message: RedactExternalPreviewTokens::redact($record->message),
                    context: RedactExternalPreviewTokens::redactMixed($record->context),
                    extra: RedactExternalPreviewTokens::redactMixed($record->extra),
                );
            }
        });
    }

    public static function redact(string $value): string
    {
        $redacted = preg_replace('#(/external-file-preview/)[A-Za-z0-9_-]{20,}#', '$1[redacted]', $value);

        return is_string($redacted) ? $redacted : $value;
    }

    public static function redactMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = self::redactMixed($item);
        }

        return $redacted;
    }
}
