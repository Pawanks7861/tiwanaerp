<?php

namespace App\Integrations\Tally;

/** The endpoint could not be reached, or the wait exceeded the timeout. */
class TallyTransportException extends TallyException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
