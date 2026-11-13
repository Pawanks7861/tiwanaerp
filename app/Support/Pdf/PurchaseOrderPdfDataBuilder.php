<?php

namespace App\Support\Pdf;

use App\Enums\IndianState;
use App\Enums\Procurement\TaxType;
use App\Models\Core\Company;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Support\Format\IndianNumber;
use App\Support\Math\Decimal;

/**
 * Presentation DTO for PO PDFs. Uses stored PO totals only — no GST recalculation.
 *
 * @phpstan-type PartyBlock array{label: string, name: ?string, lines: list<string>, meta: list<string>}
 */
final class PurchaseOrderPdfDataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PurchaseOrder $po, Project $project, Company $company): array
    {
        $po->loadMissing([
            'vendor',
            'approver:id,name',
            'items.unit:id,symbol',
            'items.materialRequestItem.materialRequest',
        ]);

        $settings = DocumentPdfSettings::for($company);
        $intra = $po->tax_type === TaxType::Intra;
        $vendor = $po->vendor;

        $items = $po->items->values()->map(function ($item, int $index) {
            $mr = $item->materialRequestItem?->materialRequest;

            return [
                'sr' => $index + 1,
                'description' => (string) $item->description,
                'item_code' => $item->item_code ?: null,
                'hsn_sac' => $item->hsn_sac ?: null,
                'mr_ref' => $mr?->request_number,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit?->symbol,
                'rate' => (string) $item->rate,
                'discount_percent' => (string) $item->discount_percent,
                'taxable_amount' => (string) $item->taxable_amount,
                'cgst_rate' => (string) $item->cgst_rate,
                'cgst_amount' => (string) $item->cgst_amount,
                'sgst_rate' => (string) $item->sgst_rate,
                'sgst_amount' => (string) $item->sgst_amount,
                'igst_rate' => (string) $item->igst_rate,
                'igst_amount' => (string) $item->igst_amount,
                'amount' => (string) $item->amount,
            ];
        })->all();

        $totalQty = $po->items->reduce(
            fn (Decimal $sum, $item) => $sum->plus($item->quantity),
            Decimal::of('0')
        );

        return [
            'po' => $po,
            'project' => $project,
            'company' => $company,
            'settings' => $settings,
            'intra' => $intra,
            'title' => 'PURCHASE ORDER',
            'invoice_to' => $this->party('Invoice To', $company->legal_name ?: $company->name, array_filter([
                $po->billing_address ?: $company->address,
                $po->billing_address ? null : collect([$company->city, $company->pincode])->filter()->implode(' - '),
            ]), array_filter([
                $settings['former_name'] ? 'Formerly: '.$settings['former_name'] : null,
                $company->gstin ? 'GSTIN/UIN: '.$company->gstin : null,
                $company->pan ? 'PAN: '.$company->pan : null,
                $company->state_code
                    ? 'State: '.(IndianState::tryFrom((string) $company->state_code)?->label() ?? $company->state_code).' ('.$company->state_code.')'
                    : null,
                $settings['cin'] ? 'CIN: '.$settings['cin'] : null,
                $company->email ?: null,
                $company->phone ?: null,
            ])),
            'consignee' => $this->party('Consignee (Ship to)', $project->name, array_filter([
                $po->shipping_address ?: $project->address,
                $po->shipping_address ? null : collect([$project->city, $project->state_code])->filter()->implode(', '),
            ]), array_filter([
                $project->code ? 'Project: '.$project->code : null,
                $company->gstin ? 'GSTIN/UIN: '.$company->gstin : null,
                $company->pan ? 'PAN: '.$company->pan : null,
                ($po->place_of_supply_state ?: $project->state_code)
                    ? 'State: '.(IndianState::tryFrom((string) ($po->place_of_supply_state ?: $project->state_code))?->label()
                        ?? ($po->place_of_supply_state ?: $project->state_code)).' ('.($po->place_of_supply_state ?: $project->state_code).')'
                    : null,
            ])),
            'supplier' => $this->party('Supplier (Bill from)', $vendor?->name, array_filter([
                $vendor?->address,
                collect([$vendor?->city, $vendor?->pincode])->filter()->implode(' - '),
            ]), array_filter([
                $vendor?->code ? 'Code: '.$vendor->code : null,
                $vendor?->gstin ? 'GSTIN: '.$vendor->gstin : null,
                $vendor?->pan ? 'PAN: '.$vendor->pan : null,
                $po->vendor_state_code
                    ? 'State: '.(IndianState::tryFrom((string) $po->vendor_state_code)?->label() ?? $po->vendor_state_code).' ('.$po->vendor_state_code.')'
                    : null,
                $vendor?->contact_person ?: null,
                $vendor?->mobile ?: null,
                $vendor?->email ?: null,
            ])),
            'meta' => array_filter([
                'Voucher No.' => $po->po_number.($po->revision_no > 0 ? ' (Rev '.$po->revision_no.')' : ''),
                'Date' => $po->po_date?->format('d-M-Y'),
                'Mode/Terms of Payment' => $po->payment_terms ?: null,
                'Other References' => $po->remarks ?: null,
                'Destination' => $po->shipping_address ? str($po->shipping_address)->before("\n")->toString() : null,
                'Terms of Delivery' => $po->delivery_date?->format('d-M-Y'),
                'Place of Supply' => IndianState::tryFrom((string) $po->place_of_supply_state)?->label(),
                'Tax Type' => $po->tax_type?->label(),
            ], fn ($v) => filled($v)),
            'bank' => $vendor && filled($vendor->bank_name) ? array_filter([
                'Bank' => $vendor->bank_name,
                'A/c No.' => $vendor->bank_account_no,
                'IFSC' => $vendor->bank_ifsc,
            ], fn ($v) => filled($v)) : [],
            'items' => $items,
            'totals' => [
                'subtotal' => (string) $po->subtotal,
                'discount_amount' => (string) $po->discount_amount,
                'taxable_amount' => (string) $po->taxable_amount,
                'cgst_amount' => (string) $po->cgst_amount,
                'sgst_amount' => (string) $po->sgst_amount,
                'igst_amount' => (string) $po->igst_amount,
                'freight_amount' => (string) $po->freight_amount,
                'other_charges' => (string) $po->other_charges,
                'round_off' => (string) $po->round_off,
                'grand_total' => (string) $po->grand_total,
                'total_quantity' => $totalQty->toQuantity(),
            ],
            'words' => IndianNumber::rupeesInWords((string) $po->grand_total),
            'terms' => filled($po->terms) ? (string) $po->terms : null,
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'nonZero' => fn ($v) => ! Decimal::of((string) $v)->isZero(),
        ];
    }

    /**
     * @param  list<string|null>  $lines
     * @param  list<string|null>  $meta
     * @return PartyBlock
     */
    private function party(string $label, ?string $name, array $lines, array $meta): array
    {
        return [
            'label' => $label,
            'name' => $name,
            'lines' => array_values(array_filter($lines, fn ($l) => filled($l))),
            'meta' => array_values(array_filter($meta, fn ($l) => filled($l))),
        ];
    }
}
