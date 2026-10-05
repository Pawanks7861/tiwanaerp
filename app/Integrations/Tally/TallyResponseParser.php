<?php

namespace App\Integrations\Tally;

use DOMDocument;
use DOMXPath;

/**
 * Reads a Tally XML body. HTTP 200 is not treated as success: import errors live in the body.
 */
class TallyResponseParser
{
    /**
     * @return list<string>
     */
    public function companies(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $names = [];
        foreach (['//COMPANY', '//COMPANYNAME', '//NAME'] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $value = trim($node->textContent);
                if ($value !== '' && ! in_array($value, $names, true)) {
                    $names[] = $value;
                }
            }
            if ($names !== []) {
                break;
            }
        }

        return $names;
    }

    /**
     * @return array{created: int, altered: int, deleted: int, errors: int, line_errors: list<string>, guid: ?string, last_vch_id: ?string, last_mid: ?string}
     */
    public function import(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $lines = [];
        foreach ($xpath->query('//LINEERROR') ?: [] as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return [
            'created' => $this->int($xpath, 'CREATED'),
            'altered' => $this->int($xpath, 'ALTERED'),
            'deleted' => $this->int($xpath, 'DELETED'),
            'errors' => $this->int($xpath, 'ERRORS'),
            'line_errors' => $lines,
            'guid' => $this->text($xpath, 'GUID'),
            'last_vch_id' => $this->text($xpath, 'LASTVCHID'),
            'last_mid' => $this->text($xpath, 'LASTMID'),
        ];
    }

    public function importSucceeded(array $parsed): bool
    {
        return $parsed['line_errors'] === []
            && $parsed['errors'] === 0
            && ($parsed['created'] + $parsed['altered'] + $parsed['deleted']) > 0;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        if ($xml === '' || ! @$dom->loadXML($xml)) {
            throw new TallyResponseException('Invalid response', 'invalid_response');
        }

        return new DOMXPath($dom);
    }

    private function int(DOMXPath $xpath, string $tag): int
    {
        $text = $this->text($xpath, $tag);

        return $text === null ? 0 : (int) $text;
    }

    private function text(DOMXPath $xpath, string $tag): ?string
    {
        $nodes = $xpath->query('//'.$tag);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim($nodes->item(0)->textContent);

        return $value === '' ? null : $value;
    }
}
