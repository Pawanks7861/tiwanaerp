<?php

namespace App\Integrations\Tally;

use App\Models\Integrations\TallyConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tally XML over HTTP. Direct mode and a future local connector both speak this protocol.
 * The host and port always come from the company connection. Nothing here is hard-coded.
 */
class TallyXmlClient implements TallyClientInterface
{
    public function __construct(private readonly TallyConnection $connection) {}

    public function endpoint(): string
    {
        return $this->connection->endpoint();
    }

    public function postXml(string $xml): string
    {
        try {
            $response = Http::timeout($this->connection->timeout_seconds)
                ->withBody($xml, 'text/xml; charset=utf-8')
                ->post($this->endpoint());
        } catch (ConnectionException $exception) {
            throw $this->transport($exception->getMessage());
        }

        if (! $response->successful()) {
            throw new TallyResponseException('Invalid response', 'invalid_response');
        }

        return $response->body();
    }

    private function transport(string $message): TallyTransportException
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'curl error 28')) {
            return new TallyTransportException('Timeout', 'timeout');
        }
        if (str_contains($lower, 'refused') || str_contains($lower, 'curl error 7')) {
            return new TallyTransportException('Wrong port', 'wrong_port');
        }

        return new TallyTransportException('Tally not reachable', 'unreachable');
    }
}
