<?php

namespace App\Integrations\Tally;

use DOMDocument;
use DOMElement;

/**
 * Builds Tally XML with DOM so names and amounts are escaped. User text is never concatenated.
 */
class TallyXmlBuilder
{
    public function companyList(): string
    {
        $dom = $this->document();
        $envelope = $this->envelope($dom);
        $header = $this->child($dom, $envelope, 'HEADER');
        $this->text($dom, $header, 'VERSION', '1');
        $this->text($dom, $header, 'TALLYREQUEST', 'Export');
        $this->text($dom, $header, 'TYPE', 'Collection');
        $this->text($dom, $header, 'ID', 'List of Companies');
        $body = $this->child($dom, $envelope, 'BODY');
        $desc = $this->child($dom, $body, 'DESC');
        $vars = $this->child($dom, $desc, 'STATICVARIABLES');
        $this->text($dom, $vars, 'SVEXPORTFORMAT', '$$SysName:XML');

        return $dom->saveXML();
    }

    public function voucher(TallyVoucher $voucher, string $company, string $action = 'Create'): string
    {
        $dom = $this->document();
        $envelope = $this->envelope($dom);
        $header = $this->child($dom, $envelope, 'HEADER');
        $this->text($dom, $header, 'TALLYREQUEST', 'Import Data');
        $body = $this->child($dom, $envelope, 'BODY');
        $import = $this->child($dom, $body, 'IMPORTDATA');
        $desc = $this->child($dom, $import, 'REQUESTDESC');
        $this->text($dom, $desc, 'REPORTNAME', 'Vouchers');
        $vars = $this->child($dom, $desc, 'STATICVARIABLES');
        $this->text($dom, $vars, 'SVCURRENTCOMPANY', $company);
        $data = $this->child($dom, $import, 'REQUESTDATA');
        $message = $this->child($dom, $data, 'TALLYMESSAGE');
        $node = $dom->createElement('VOUCHER');
        $node->setAttribute('VCHTYPE', $voucher->voucherType);
        $node->setAttribute('ACTION', $action === 'Cancel' ? 'Cancel' : 'Create');
        $node->setAttribute('OBJVIEW', 'Accounting Voucher View');
        $message->appendChild($node);
        $this->text($dom, $node, 'DATE', str_replace('-', '', $voucher->date));
        $this->text($dom, $node, 'VOUCHERTYPENAME', $voucher->voucherType);
        $this->text($dom, $node, 'VOUCHERNUMBER', $voucher->number);
        $this->text($dom, $node, 'REFERENCE', $voucher->reference);
        $this->text($dom, $node, 'NARRATION', $voucher->narration);
        $this->text($dom, $node, 'PARTYLEDGERNAME', $voucher->partyLedger);
        foreach ($voucher->lines as $line) {
            $this->ledgerEntry($dom, $node, $line, $voucher->costCentre);
        }

        return $dom->saveXML();
    }

    /**
     * @param  array{name: string, parent: string, address?: string, gstin?: string, state?: string, pan?: string}  $ledger
     */
    public function ledger(array $ledger, string $company): string
    {
        $dom = $this->document();
        $envelope = $this->envelope($dom);
        $header = $this->child($dom, $envelope, 'HEADER');
        $this->text($dom, $header, 'TALLYREQUEST', 'Import Data');
        $body = $this->child($dom, $envelope, 'BODY');
        $import = $this->child($dom, $body, 'IMPORTDATA');
        $desc = $this->child($dom, $import, 'REQUESTDESC');
        $this->text($dom, $desc, 'REPORTNAME', 'All Masters');
        $vars = $this->child($dom, $desc, 'STATICVARIABLES');
        $this->text($dom, $vars, 'SVCURRENTCOMPANY', $company);
        $data = $this->child($dom, $import, 'REQUESTDATA');
        $message = $this->child($dom, $data, 'TALLYMESSAGE');
        $node = $dom->createElement('LEDGER');
        $node->setAttribute('NAME', $ledger['name']);
        $node->setAttribute('ACTION', 'Create');
        $message->appendChild($node);
        $this->text($dom, $node, 'NAME', $ledger['name']);
        $this->text($dom, $node, 'PARENT', $ledger['parent']);
        if (($ledger['address'] ?? '') !== '') {
            $address = $this->child($dom, $node, 'ADDRESS.LIST');
            $this->text($dom, $address, 'ADDRESS', $ledger['address']);
        }
        if (($ledger['gstin'] ?? '') !== '') {
            $this->text($dom, $node, 'PARTYGSTIN', $ledger['gstin']);
        }
        if (($ledger['state'] ?? '') !== '') {
            $this->text($dom, $node, 'LEDSTATENAME', $ledger['state']);
        }
        if (($ledger['pan'] ?? '') !== '') {
            $this->text($dom, $node, 'INCOMETAXNUMBER', $ledger['pan']);
        }

        return $dom->saveXML();
    }

    /**
     * @param  array{ledger: string, debit: string, credit: string}  $line
     */
    private function ledgerEntry(DOMDocument $dom, DOMElement $voucher, array $line, ?string $costCentre): void
    {
        $debit = $line['debit'] !== '0.00';
        $amount = $debit ? '-'.$line['debit'] : $line['credit'];
        $entry = $this->child($dom, $voucher, 'ALLLEDGERENTRIES.LIST');
        $this->text($dom, $entry, 'LEDGERNAME', $line['ledger']);
        $this->text($dom, $entry, 'ISDEEMEDPOSITIVE', $debit ? 'Yes' : 'No');
        $this->text($dom, $entry, 'AMOUNT', $amount);
        if ($costCentre) {
            $allocation = $this->child($dom, $entry, 'COSTCENTREALLOCATIONS.LIST');
            $this->text($dom, $allocation, 'NAME', $costCentre);
            $this->text($dom, $allocation, 'AMOUNT', $amount);
        }
    }

    private function document(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        return $dom;
    }

    private function envelope(DOMDocument $dom): DOMElement
    {
        $envelope = $dom->createElement('ENVELOPE');
        $dom->appendChild($envelope);

        return $envelope;
    }

    private function child(DOMDocument $dom, DOMElement $parent, string $name): DOMElement
    {
        $node = $dom->createElement($name);
        $parent->appendChild($node);

        return $node;
    }

    private function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $node = $dom->createElement($name);
        $node->appendChild($dom->createTextNode($value));
        $parent->appendChild($node);
    }
}
