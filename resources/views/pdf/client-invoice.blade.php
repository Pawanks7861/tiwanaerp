<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number ?? 'RA '.$invoice->ra_sequence }}</title>
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
        .watermark { position: fixed; top: 40%; left: 15%; font-size: 60px; color: #e2e8f0; transform: rotate(-30deg); }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; }
    </style>
</head>
<body>
    @php($intra = $invoice->tax_type?->value === 'intra')
    @php($certified = $invoice->invoice_number !== null)
    @php($nonZero = fn ($v) => ! \App\Support\Math\Decimal::of((string) $v)->isZero())

    @unless ($certified)<div class="watermark">DRAFT</div>@endunless

    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @include('pdf.partials.logo')
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                <div class="muted" style="margin-top: 3px; line-height: 1.4;">
                    {!! nl2br(e($company->address)) !!}@if ($company->city)<br>{{ $company->city }}@if ($company->pincode) - {{ $company->pincode }}@endif @endif
                    @if ($company->gstin)<br>GSTIN: <strong>{{ $company->gstin }}</strong>@endif
                    <br>State: {{ $supplierState ?? '—' }} ({{ $invoice->supplier_state }})
                </div>
            </td>
            <td style="width: 40%; vertical-align: top;" class="right">
                <h2>{{ $certified ? 'TAX INVOICE' : 'RA BILL (DRAFT)' }}</h2>
                <div style="font-size: 12px; font-weight: bold;">{{ $invoice->invoice_number ?? '—' }}</div>
                <div class="muted" style="margin-top: 3px;">Running account bill no. {{ $invoice->ra_sequence }}</div>
                <div class="muted">Date: {{ $invoice->invoice_date?->format('d M Y') }}</div>
                <div class="muted">Work period: {{ $invoice->period_from?->format('d M Y') }} – {{ $invoice->period_to?->format('d M Y') }}</div>
                <div style="margin-top: 3px;"><span class="badge">{{ $invoice->status->label() }}</span></div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td class="box" style="width: 50%;">
                <div class="label">Bill to</div>
                <strong>{{ $invoice->client?->company_name }}</strong> ({{ $invoice->client?->code }})<br>
                {!! nl2br(e($invoice->client?->billing_address)) !!}@if ($invoice->client?->city)<br>{{ $invoice->client->city }}@endif
                @if ($invoice->client?->gstin)<br>GSTIN: {{ $invoice->client->gstin }}@endif
            </td>
            <td class="box" style="width: 50%;">
                <div class="label">Project / site</div>
                {{ $project->code }} · {{ $project->name }}<br>
                {!! nl2br(e($project->address)) !!}@if ($project->city)<br>{{ $project->city }}@endif
                <br><span class="label">Place of supply</span> {{ $posState ?? '—' }} ({{ $invoice->place_of_supply_state }})
                · <span class="label">Tax</span> {{ $invoice->tax_type?->label() }}
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 8%;">Item</th>
                <th style="width: 27%; text-align: left;">Description</th>
                <th>Unit</th>
                <th>BOQ qty</th>
                <th>Previous</th>
                <th>This bill</th>
                <th>Cumulative</th>
                <th>Rate</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $item)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="center">{{ $item->item_code ?: '—' }}</td>
                    <td>{{ $item->description }}@if ($item->is_override)<br><span class="muted">Quantity override: {{ $item->override_reason }}</span>@endif</td>
                    <td class="center">{{ $item->unit?->symbol }}</td>
                    <td class="right">{{ $qty($item->boq_qty) }}</td>
                    <td class="right">{{ $qty($item->previous_qty) }}</td>
                    <td class="right">{{ $qty($item->current_qty) }}</td>
                    <td class="right">{{ $qty($item->cumulative_qty) }}</td>
                    <td class="right">{{ $money($item->rate) }}</td>
                    <td class="right">{{ $money($item->current_amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 12px;">
                <div class="label">Net payable in words</div>
                <div style="margin-bottom: 8px;"><strong>{{ $words }}</strong></div>
                @if ($invoice->remarks)
                    <div class="label">Remarks</div>
                    <div>{!! nl2br(e($invoice->remarks)) !!}</div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals">
                    <tr><td>Gross value of work (taxable)</td><td class="right">{{ $money($invoice->gross_amount) }}</td></tr>
                    @if ($intra)
                        <tr><td>CGST</td><td class="right">{{ $money($invoice->cgst_amount) }}</td></tr>
                        <tr><td>SGST</td><td class="right">{{ $money($invoice->sgst_amount) }}</td></tr>
                    @else
                        <tr><td>IGST</td><td class="right">{{ $money($invoice->igst_amount) }}</td></tr>
                    @endif
                    <tr><td><strong>Invoice total</strong></td><td class="right"><strong>{{ $money($invoice->invoice_total) }}</strong></td></tr>
                    @if ($nonZero($invoice->retention_amount))<tr><td>Less retention ({{ rtrim(rtrim((string) $invoice->retention_percent, '0'), '.') }}%)</td><td class="right">-{{ $money($invoice->retention_amount) }}</td></tr>@endif
                    @if ($nonZero($invoice->advance_recovery))<tr><td>Less advance recovery</td><td class="right">-{{ $money($invoice->advance_recovery) }}</td></tr>@endif
                    @if ($nonZero($invoice->tds_amount))<tr><td>Less TDS ({{ rtrim(rtrim((string) $invoice->tds_percent, '0'), '.') }}%)</td><td class="right">-{{ $money($invoice->tds_amount) }}</td></tr>@endif
                    @if ($nonZero($invoice->other_deductions))<tr><td>Less other deductions</td><td class="right">-{{ $money($invoice->other_deductions) }}</td></tr>@endif
                    <tr class="grand"><td>Net payable (INR)</td><td class="right">{{ $money($invoice->net_payable) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 30px;">
        <tr>
            <td style="width: 50%;" class="muted">
                @if ($invoice->certified_at)Certified on {{ $invoice->certified_at->format('d M Y') }}@else Not yet certified @endif
            </td>
            <td style="width: 50%;" class="right">
                <div style="margin-top: 26px; border-top: 1px solid #94a3b8; display: inline-block; padding-top: 3px; min-width: 180px;" class="center">
                    For {{ $company->legal_name ?: $company->name }}<br><span class="muted">Authorised signatory</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $invoice->invoice_number ?? 'RA '.$invoice->ra_sequence.' (draft)' }} · Generated {{ now()->format('d M Y H:i') }} · Amounts as stored on the bill.</div>
</body>
</html>
