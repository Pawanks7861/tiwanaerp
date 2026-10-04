<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $po->po_number }}</title>
    <style>
        @page { margin: 22px 26px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 13px; margin: 0 0 2px; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .box { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        .label { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .lines th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 3px; font-size: 7.5px; text-transform: uppercase; }
        .lines td { border: 1px solid #e2e8f0; padding: 4px 3px; vertical-align: top; }
        .totals td { padding: 3px 6px; }
        .totals .grand td { border-top: 1.5px solid #1e293b; font-weight: bold; font-size: 11px; }
        .badge { display: inline-block; padding: 2px 6px; border: 1px solid #94a3b8; border-radius: 3px; font-size: 8px; text-transform: uppercase; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; }
    </style>
</head>
<body>
    @php($intra = $po->tax_type?->value === 'intra')
    @php($nonZero = fn ($v) => ! \App\Support\Math\Decimal::of((string) $v)->isZero())

    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @include('pdf.partials.logo')
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                <div class="muted" style="margin-top: 3px; line-height: 1.4;">
                    {!! nl2br(e($company->address)) !!}@if ($company->city)<br>{{ $company->city }}@if ($company->pincode) - {{ $company->pincode }}@endif @endif
                    @if ($company->gstin)<br>GSTIN: <strong>{{ $company->gstin }}</strong>@endif
                    @if ($company->phone || $company->email)<br>{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}@endif
                </div>
            </td>
            <td style="width: 40%; vertical-align: top;" class="right">
                <h2>PURCHASE ORDER</h2>
                <div style="font-size: 12px; font-weight: bold;">{{ $po->po_number }}@if ($po->revision_no > 0) <span class="badge">Rev {{ $po->revision_no }}</span>@endif</div>
                <div class="muted" style="margin-top: 3px;">Date: {{ $po->po_date?->format('d M Y') }}</div>
                @if ($po->delivery_date)<div class="muted">Delivery by: {{ $po->delivery_date->format('d M Y') }}</div>@endif
                <div style="margin-top: 3px;"><span class="badge">{{ $po->status->label() }}</span></div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td class="box" style="width: 34%;">
                <div class="label">Vendor</div>
                <strong>{{ $po->vendor?->name }}</strong> ({{ $po->vendor?->code }})<br>
                {!! nl2br(e($po->vendor?->address)) !!}@if ($po->vendor?->city)<br>{{ $po->vendor->city }}@endif
                @if ($po->vendor?->gstin)<br>GSTIN: {{ $po->vendor->gstin }}@endif
                <br>State: {{ $vendorState ?? '—' }} ({{ $po->vendor_state_code }})
            </td>
            <td class="box" style="width: 33%;">
                <div class="label">Bill to</div>
                {!! nl2br(e($po->billing_address)) !!}
            </td>
            <td class="box" style="width: 33%;">
                <div class="label">Ship to</div>
                {!! nl2br(e($po->shipping_address)) !!}
                <br><span class="muted">Project: {{ $project->code }} · {{ $project->name }}</span>
            </td>
        </tr>
        <tr>
            <td class="box" colspan="3">
                <span class="label">Place of supply</span> {{ $placeOfSupply ?? '—' }} ({{ $po->place_of_supply_state }})
                &nbsp;·&nbsp; <span class="label">Tax</span> {{ $po->tax_type?->label() }}
                @if ($po->payment_terms) &nbsp;·&nbsp; <span class="label">Payment terms</span> {{ $po->payment_terms }}@endif
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 22%; text-align: left;">Item</th>
                <th>HSN/SAC</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Rate</th>
                <th>Disc %</th>
                <th>Taxable</th>
                @if ($intra)
                    <th>CGST %</th><th>CGST</th><th>SGST %</th><th>SGST</th>
                @else
                    <th>IGST %</th><th>IGST</th>
                @endif
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $item)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $item->description }}@if ($item->item_code)<br><span class="muted">{{ $item->item_code }}</span>@endif</td>
                    <td class="center">{{ $item->hsn_sac ?: '—' }}</td>
                    <td class="right">{{ $qty($item->quantity) }}</td>
                    <td class="center">{{ $item->unit?->symbol }}</td>
                    <td class="right">{{ $money($item->rate) }}</td>
                    <td class="right">{{ rtrim(rtrim((string) $item->discount_percent, '0'), '.') ?: '0' }}</td>
                    <td class="right">{{ $money($item->taxable_amount) }}</td>
                    @if ($intra)
                        <td class="right">{{ rtrim(rtrim((string) $item->cgst_rate, '0'), '.') ?: '0' }}</td>
                        <td class="right">{{ $money($item->cgst_amount) }}</td>
                        <td class="right">{{ rtrim(rtrim((string) $item->sgst_rate, '0'), '.') ?: '0' }}</td>
                        <td class="right">{{ $money($item->sgst_amount) }}</td>
                    @else
                        <td class="right">{{ rtrim(rtrim((string) $item->igst_rate, '0'), '.') ?: '0' }}</td>
                        <td class="right">{{ $money($item->igst_amount) }}</td>
                    @endif
                    <td class="right">{{ $money($item->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 12px;">
                <div class="label">Amount in words</div>
                <div style="margin-bottom: 8px;"><strong>{{ $words }}</strong></div>
                @if ($po->terms)
                    <div class="label">Terms &amp; conditions</div>
                    <div style="line-height: 1.4;">{!! nl2br(e($po->terms)) !!}</div>
                @endif
                @if ($po->remarks)
                    <div class="label" style="margin-top: 6px;">Remarks</div>
                    <div>{!! nl2br(e($po->remarks)) !!}</div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals">
                    <tr><td>Sub total</td><td class="right">{{ $money($po->subtotal) }}</td></tr>
                    @if ($nonZero($po->discount_amount))<tr><td>Discount</td><td class="right">-{{ $money($po->discount_amount) }}</td></tr>@endif
                    <tr><td>Taxable value</td><td class="right">{{ $money($po->taxable_amount) }}</td></tr>
                    @if ($intra)
                        <tr><td>CGST</td><td class="right">{{ $money($po->cgst_amount) }}</td></tr>
                        <tr><td>SGST</td><td class="right">{{ $money($po->sgst_amount) }}</td></tr>
                    @else
                        <tr><td>IGST</td><td class="right">{{ $money($po->igst_amount) }}</td></tr>
                    @endif
                    @if ($nonZero($po->freight_amount))<tr><td>Freight</td><td class="right">{{ $money($po->freight_amount) }}</td></tr>@endif
                    @if ($nonZero($po->other_charges))<tr><td>Other charges</td><td class="right">{{ $money($po->other_charges) }}</td></tr>@endif
                    <tr><td>Round off</td><td class="right">{{ $money($po->round_off) }}</td></tr>
                    <tr class="grand"><td>Grand total (INR)</td><td class="right">{{ $money($po->grand_total) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 30px;">
        <tr>
            <td style="width: 50%;" class="muted">
                @if ($po->approved_at)Approved by {{ $po->approver?->name }} on {{ $po->approved_at->format('d M Y') }}@else Not yet approved @endif
            </td>
            <td style="width: 50%;" class="right">
                <div style="margin-top: 26px; border-top: 1px solid #94a3b8; display: inline-block; padding-top: 3px; min-width: 180px;" class="center">
                    For {{ $company->legal_name ?: $company->name }}<br><span class="muted">Authorised signatory</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $po->po_number }}@if ($po->revision_no > 0) · Revision {{ $po->revision_no }}@endif · Generated {{ now()->format('d M Y H:i') }} · Amounts as stored on the purchase order.</div>
</body>
</html>
