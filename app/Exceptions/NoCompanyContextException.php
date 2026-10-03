<?php

namespace App\Exceptions;

use RuntimeException;

class NoCompanyContextException extends RuntimeException
{
    public function __construct(string $message = 'No active company context.')
    {
        parent::__construct($message);
    }
}
