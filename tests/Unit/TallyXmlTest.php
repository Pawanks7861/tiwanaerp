<?php

use App\Integrations\Tally\TallyResponseParser;
use App\Integrations\Tally\TallyVoucher;
use App\Integrations\Tally\TallyXmlBuilder;

test('the xml builder escapes names and keeps unicode', function () {
    $voucher = new TallyVoucher(
        voucherType: 'Journal',
        date: '2026-10-05',
        number: 'VB-1',
        reference: 'ERP:vendor_bill:1',
        narration: 'A & B',
        partyLedger: 'Sharma & Co',
        lines: [
            ['ledger' => 'Sharma & Co', 'debit' => '0.00', 'credit' => '10.00'],
            ['ledger' => 'नगर Stores', 'debit' => '10.00', 'credit' => '0.00'],
        ],
    );
    $xml = (new TallyXmlBuilder)->voucher($voucher, 'Test "Books"');
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue()
        ->and($xml)->toContain('Sharma &amp; Co')
        ->and($xml)->toContain('नगर Stores')
        ->and($xml)->toContain('A &amp; B');
});

test('a tally import error is not success when http would be 200', function () {
    $parser = new TallyResponseParser;
    $parsed = $parser->import('<ENVELOPE><CREATED>0</CREATED><ERRORS>1</ERRORS><LINEERROR>Ledger missing</LINEERROR></ENVELOPE>');

    expect($parser->importSucceeded($parsed))->toBeFalse()
        ->and($parsed['line_errors'])->toBe(['Ledger missing'])
        ->and($parsed['errors'])->toBe(1);
});

test('a created voucher is read from the tally body', function () {
    $parser = new TallyResponseParser;
    $parsed = $parser->import('<ENVELOPE><CREATED>1</CREATED><ERRORS>0</ERRORS><GUID>guid-1</GUID><LASTVCHID>9</LASTVCHID></ENVELOPE>');

    expect($parser->importSucceeded($parsed))->toBeTrue()
        ->and($parsed['guid'])->toBe('guid-1')
        ->and($parsed['last_vch_id'])->toBe('9');
});

test('malformed tally xml is rejected', function () {
    expect(fn () => (new TallyResponseParser)->companies('<not-xml'))
        ->toThrow(App\Integrations\Tally\TallyResponseException::class);
});
