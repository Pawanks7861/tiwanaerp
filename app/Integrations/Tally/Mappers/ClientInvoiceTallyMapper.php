<?php

namespace App\Integrations\Tally\Mappers;

use App\Integrations\Tally\TallyLedgerMapper;
use App\Integrations\Tally\TallyVoucher;
use App\Models\Finance\ClientInvoice;

/**
 * Certified client RA invoice. Amounts are the stored certified figures, not a second tax calculation.
 *
 * Dr client net, retention receivable, TDS receivable, client advance recovery, other deductions.
 * Cr work income (gross) and output CGST / SGST / IGST.
 */
class ClientInvoiceTallyMapper
{
    use BuildsVoucherLines;

    public function map(ClientInvoice $invoice, TallyLedgerMapper $ledgers, ?string $costCentre): TallyVoucher
    {
        $reference = $this->reference('client_invoice', $invoice->id);
        $client = $ledgers->party('client', (int) $invoice->client_id, 'Client');
        $voucher = new TallyVoucher(
            voucherType: 'Sales',
            date: $invoice->invoice_date->toDateString(),
            number: (string) $invoice->invoice_number,
            reference: $reference,
            narration: $this->narration($invoice->project?->code, $reference),
            partyLedger: $client,
            lines: $this->lines([
                $this->debit($client, $invoice->net_payable),
                $this->debit($ledgers->system('retention_receivable', 'Retention receivable'), $invoice->retention_amount),
                $this->debit($ledgers->system('tds_receivable', 'TDS receivable'), $invoice->tds_amount),
                $this->debit($ledgers->system('advance_client', 'Client advance'), $invoice->advance_recovery),
                $this->debit($ledgers->system('other_deduction', 'Other deductions'), $invoice->other_deductions),
                $this->credit($ledgers->system('sales', 'Work income / sales'), $invoice->gross_amount),
                $this->credit($ledgers->system('gst_output_cgst', 'Output CGST'), $invoice->cgst_amount),
                $this->credit($ledgers->system('gst_output_sgst', 'Output SGST'), $invoice->sgst_amount),
                $this->credit($ledgers->system('gst_output_igst', 'Output IGST'), $invoice->igst_amount),
            ]),
            costCentre: $costCentre,
        );
        $voucher->assertBalanced();

        return $voucher;
    }
}
