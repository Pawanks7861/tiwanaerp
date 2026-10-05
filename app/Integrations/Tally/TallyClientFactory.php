<?php

namespace App\Integrations\Tally;

use App\Models\Integrations\TallyConnection;

class TallyClientFactory
{
    public function make(TallyConnection $connection): TallyClientInterface
    {
        return $connection->format === 'json'
            ? new TallyJsonClient
            : new TallyXmlClient($connection);
    }
}
