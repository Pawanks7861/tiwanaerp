<?php

namespace App\Integrations\Tally;

/**
 * Transport used to reach Tally. A future connector or JSON client implements the same method.
 */
interface TallyClientInterface
{
    public function postXml(string $xml): string;

    public function endpoint(): string;
}
