<?php

namespace App\Integrations\Tally;

/** Tally answered, but the body is not a successful import or company check. */
class TallyResponseException extends TallyException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
