<?php

namespace App\Integrations\Tally;

/**
 * Reserved for TallyPrime 7 JSON. The business mappers do not depend on this class.
 */
class TallyJsonClient implements TallyClientInterface
{
    public function endpoint(): string
    {
        return '';
    }

    public function postXml(string $xml): string
    {
        throw new TallyResponseException(
            'JSON transport is not available yet. Use XML, which works with current TallyPrime releases.',
            'json_unsupported',
        );
    }
}
