<?php

namespace App\Integrations\Tally;

class MissingTallyMappingException extends TallyException
{
    public function __construct(public readonly string $mappingLabel)
    {
        parent::__construct("Missing Tally ledger mapping: {$mappingLabel}");
    }
}
