<?php

namespace App\Integrations\Tally;

use App\Models\Integrations\TallyConnection;

class TallyConnectionTester
{
    public function __construct(
        private readonly TallyClientFactory $clients,
        private readonly TallyXmlBuilder $xml,
        private readonly TallyResponseParser $parser,
    ) {}

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    public function test(TallyConnection $connection, bool $persist = true): array
    {
        $result = $this->probe($connection);
        if ($persist) {
            $connection->forceFill([
                'last_checked_at' => now(),
                'last_connected_at' => $result['ok'] ? now() : $connection->last_connected_at,
                'last_status' => $result['ok'] ? 'connected' : 'offline',
                'last_status_message' => $result['message'],
            ])->save();
        }

        return $result;
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    private function probe(TallyConnection $connection): array
    {
        if ($connection->format === 'json') {
            return ['ok' => false, 'code' => 'json_unsupported', 'message' => 'JSON transport is not available yet. Use XML.'];
        }
        if (! $connection->host || ! $connection->port || ! $connection->tally_company_name) {
            return ['ok' => false, 'code' => 'incomplete', 'message' => 'Save the host, port and Tally company name first.'];
        }

        try {
            $body = $this->clients->make($connection)->postXml($this->xml->companyList());
            $companies = $this->parser->companies($body);
        } catch (TallyTransportException $exception) {
            return ['ok' => false, 'code' => $exception->reason, 'message' => $this->transportMessage($exception->reason)];
        } catch (TallyResponseException) {
            return ['ok' => false, 'code' => 'invalid_response', 'message' => 'Invalid response'];
        }

        if ($companies === []) {
            return ['ok' => false, 'code' => 'company_not_loaded', 'message' => 'Company not loaded'];
        }

        $wanted = mb_strtolower(trim($connection->tally_company_name));
        foreach ($companies as $name) {
            if (mb_strtolower(trim($name)) === $wanted) {
                return ['ok' => true, 'code' => 'connected', 'message' => 'Connected'];
            }
        }

        return ['ok' => false, 'code' => 'company_mismatch', 'message' => 'Company name mismatch'];
    }

    private function transportMessage(string $reason): string
    {
        return match ($reason) {
            'timeout' => 'Timeout',
            'wrong_port' => 'Wrong port',
            default => 'Tally not reachable',
        };
    }
}
