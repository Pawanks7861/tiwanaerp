<?php

namespace App\Support\Pdf;

use App\Enums\IndianState;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Core\Company;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Support\Format\IndianNumber;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;

/**
 * Presentation DTO for PO PDFs. Uses stored PO totals only — no GST recalculation.
 * Prefers approval-time document_snapshot for parties/bank/settings when present.
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
            'grns' => fn ($q) => $q->latest('id')->limit(5),
        ]);

        $snapshot = is_array($po->document_snapshot) ? $po->document_snapshot : null;
        $settings = $snapshot['settings'] ?? DocumentPdfSettings::for($company);
        $intra = $po->tax_type === TaxType::Intra;
        $isDraft = ! $po->status->isApprovedOrder() && $po->status !== PurchaseOrderStatus::Closed;

        $items = $po->items->values()->map(function ($item, int $index) {
            $mr = $item->materialRequestItem?->materialRequest;
            $brandMake = collect([$item->brand, $item->make])->filter()->unique()->implode(' / ');

            return [
                'sr' => $index + 1,
                'description' => (string) $item->description,
                'item_code' => $item->item_code ?: null,
                'hsn_sac' => $item->hsn_sac ?: null,
                'brand' => $item->brand ?: null,
                'make' => $item->make ?: null,
                'brand_make' => $brandMake !== '' ? $brandMake : null,
                'mr_ref' => $mr?->request_number,
                'due_on' => $item->due_date?->format('d-M-Y'),
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

        $gateEntry = $po->grns
            ->map(fn ($g) => $g->grn_number)
            ->filter()
            ->unique()
            ->implode(', ');

        $paymentTerms = $snapshot['payment_terms'] ?? $po->payment_terms;
        $deliveryTerms = $snapshot['delivery_terms'] ?? $po->delivery_terms;
        $otherRefs = $snapshot['other_references'] ?? $po->other_references;
        $dispatched = $snapshot['dispatched_through'] ?? $po->dispatched_through;
        $destination = $snapshot['destination'] ?? $po->destination
            ?: ($po->shipping_address ? str($po->shipping_address)->before("\n")->toString() : null);
        $referenceNumber = $snapshot['reference_number'] ?? $po->reference_number;
        $referenceDate = $snapshot['reference_date'] ?? $po->reference_date?->toDateString();
        $terms = $snapshot['terms'] ?? (filled($po->terms) ? (string) $po->terms : null);
        $companyPan = $snapshot['company_pan'] ?? $company->pan;

        $refLine = null;
        if (filled($referenceNumber) || filled($referenceDate)) {
            $refLine = trim(($referenceNumber ?? '').(filled($referenceDate)
                ? ' dated '.CarbonImmutable::parse($referenceDate)->format('d-M-Y')
                : ''));
        }

        return [
            'po' => $po,
            'project' => $project,
            'company' => $company,
            'settings' => $settings,
            'intra' => $intra,
            'is_draft' => $isDraft,
            'title' => 'PURCHASE ORDER',
            'invoice_to' => $this->invoiceTo($po, $company, $settings, $snapshot),
            'consignee' => $this->consignee($po, $project, $company, $snapshot),
            'supplier' => $this->supplier($po, $snapshot),
            'meta' => array_filter([
                'Voucher No.' => $po->po_number.($po->revision_no > 0 ? ' (Rev '.$po->revision_no.')' : ''),
                'Dated' => $po->po_date?->format('d-M-Y'),
                'Mode/Terms of Payment' => $paymentTerms ?: null,
                'Reference No. & Date' => $refLine,
                'Other References' => filled($otherRefs) ? $otherRefs : (filled($po->remarks) ? $po->remarks : null),
            ], fn ($v) => filled($v)),
            'dispatch' => array_filter([
                'Dispatched Through' => $dispatched,
                'Destination' => $destination,
                'Terms of Delivery' => $deliveryTerms ?: ($po->delivery_date?->format('d-M-Y')),
                'Gate Entry No.' => filled($gateEntry) ? $gateEntry : null,
            ], fn ($v) => filled($v)),
            'bank' => $this->bank($po, $snapshot),
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
            'terms' => $terms,
            'company_pan' => $companyPan,
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'nonZero' => fn ($v) => ! Decimal::of((string) $v)->isZero(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @param  array<string, mixed>  $settings
     * @return PartyBlock
     */
    private function invoiceTo(PurchaseOrder $po, Company $company, array $settings, ?array $snapshot): array
    {
        if ($snapshot !== null && isset($snapshot['invoice_to'])) {
            $p = $snapshot['invoice_to'];

            return $this->party('Invoice To', $p['name'] ?? null, array_filter([
                filled($p['former_name'] ?? null) ? '(Formerly '.$p['former_name'].')' : null,
                $p['address'] ?? null,
            ]), array_filter([
                filled($p['pan'] ?? null) ? 'PAN: '.$p['pan'] : null,
                filled($p['gstin'] ?? null) ? 'GSTIN/UIN: '.$p['gstin'] : null,
                filled($p['state_code'] ?? null)
                    ? 'State: '.($p['state'] ?? $p['state_code']).' Code: '.$p['state_code']
                    : null,
                filled($p['cin'] ?? null) ? 'CIN: '.$p['cin'] : null,
                $p['email'] ?? null,
            ]));
        }

        return $this->party('Invoice To', $company->legal_name ?: $company->name, array_filter([
            $settings['former_name'] ? '(Formerly '.$settings['former_name'].')' : null,
            $po->billing_address ?: $company->address,
            $po->billing_address ? null : collect([$company->city, $company->pincode])->filter()->implode(' - '),
        ]), array_filter([
            $company->pan ? 'PAN: '.$company->pan : null,
            $company->gstin ? 'GSTIN/UIN: '.$company->gstin : null,
            $company->state_code
                ? 'State: '.(IndianState::tryFrom((string) $company->state_code)?->label() ?? $company->state_code).' Code: '.$company->state_code
                : null,
            $settings['cin'] ? 'CIN: '.$settings['cin'] : null,
            $company->email ?: null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return PartyBlock
     */
    private function consignee(PurchaseOrder $po, Project $project, Company $company, ?array $snapshot): array
    {
        if ($snapshot !== null && isset($snapshot['consignee'])) {
            $p = $snapshot['consignee'];

            return $this->party('Consignee (Ship to)', $p['name'] ?? null, array_filter([
                filled($p['project_code'] ?? null) ? 'Site / Plant: '.$p['project_code'] : null,
                $p['address'] ?? null,
            ]), array_filter([
                filled($p['pan'] ?? null) ? 'PAN: '.$p['pan'] : null,
                filled($p['gstin'] ?? null) ? 'GSTIN/UIN: '.$p['gstin'] : null,
                filled($p['state_code'] ?? null)
                    ? 'State: '.($p['state'] ?? $p['state_code']).' Code: '.$p['state_code']
                    : null,
                $p['email'] ?? null,
            ]));
        }

        return $this->party('Consignee (Ship to)', $po->consignee_name ?: $project->name, array_filter([
            $project->code ? 'Site / Plant: '.$project->code : null,
            $po->shipping_address ?: $project->address,
            $po->shipping_address ? null : collect([$project->city, $project->state_code])->filter()->implode(', '),
        ]), array_filter([
            $company->pan ? 'PAN: '.$company->pan : null,
            ($po->consignee_gstin ?: $company->gstin) ? 'GSTIN/UIN: '.($po->consignee_gstin ?: $company->gstin) : null,
            ($po->place_of_supply_state ?: $project->state_code)
                ? 'State: '.(IndianState::tryFrom((string) ($po->place_of_supply_state ?: $project->state_code))?->label()
                    ?? ($po->place_of_supply_state ?: $project->state_code)).' Code: '.($po->place_of_supply_state ?: $project->state_code)
                : null,
            $po->consignee_email ?: null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return PartyBlock
     */
    private function supplier(PurchaseOrder $po, ?array $snapshot): array
    {
        if ($snapshot !== null && isset($snapshot['supplier'])) {
            $p = $snapshot['supplier'];

            return $this->party('Supplier (Bill from)', $p['name'] ?? null, array_filter([
                $p['address'] ?? null,
            ]), array_filter([
                filled($p['code'] ?? null) ? 'Code: '.$p['code'] : null,
                filled($p['gstin'] ?? null) ? 'GSTIN: '.$p['gstin'] : null,
                filled($p['pan'] ?? null) ? 'PAN: '.$p['pan'] : null,
                filled($p['state_code'] ?? null)
                    ? 'State: '.($p['state'] ?? $p['state_code']).' Code: '.$p['state_code']
                    : null,
                $p['contact'] ?? null,
                $p['mobile'] ?? null,
                $p['email'] ?? null,
            ]));
        }

        $vendor = $po->vendor;

        return $this->party('Supplier (Bill from)', $vendor?->name, array_filter([
            $vendor?->address,
            collect([$vendor?->city, $vendor?->pincode])->filter()->implode(' - '),
        ]), array_filter([
            $vendor?->code ? 'Code: '.$vendor->code : null,
            $vendor?->gstin ? 'GSTIN: '.$vendor->gstin : null,
            $vendor?->pan ? 'PAN: '.$vendor->pan : null,
            $po->vendor_state_code
                ? 'State: '.(IndianState::tryFrom((string) $po->vendor_state_code)?->label() ?? $po->vendor_state_code).' Code: '.$po->vendor_state_code
                : null,
            $vendor?->contact_person ?: null,
            $vendor?->mobile ?: null,
            $vendor?->email ?: null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, string>
     */
    private function bank(PurchaseOrder $po, ?array $snapshot): array
    {
        if ($snapshot !== null && ! empty($snapshot['bank'])) {
            return array_filter([
                'Bank Name' => $snapshot['bank']['bank_name'] ?? null,
                'A/c No.' => $snapshot['bank']['account_no'] ?? null,
                'IFSC' => $snapshot['bank']['ifsc'] ?? null,
            ], fn ($v) => filled($v));
        }

        $vendor = $po->vendor;
        if ($vendor === null || blank($vendor->bank_name)) {
            return [];
        }

        return array_filter([
            'Bank Name' => $vendor->bank_name,
            'A/c No.' => $vendor->bank_account_no,
            'IFSC' => $vendor->bank_ifsc,
        ], fn ($v) => filled($v));
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
