<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $po->po_number }}</title>
    <style>
        @page { margin: 16px 18px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        h1 { font-size: 13px; margin: 0; }
        h2 { font-size: 14px; margin: 0; letter-spacing: 1.2px; text-align: center; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #555; }
        .right { text-align: right; }
        .center { text-align: center; }
        .box { border: 1px solid #333; padding: 5px 7px; vertical-align: top; }
        .label { font-size: 7.5px; color: #444; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
        .lines th { background: #f0f0f0; border: 1px solid #333; padding: 3px 2px; font-size: 7px; text-transform: uppercase; }
        .lines td { border: 1px solid #555; padding: 3px 2px; vertical-align: top; }
        .totals td { padding: 2px 5px; border: none; }
        .totals .grand td { border-top: 1.5px solid #111; font-weight: bold; font-size: 10px; padding-top: 4px; }
        .badge { display: inline-block; padding: 1px 5px; border: 1px solid #666; font-size: 7.5px; text-transform: uppercase; }
        .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7px; color: #666; text-align: center; }
        .sig { border-top: 1px solid #333; display: inline-block; min-width: 160px; padding-top: 3px; margin-top: 28px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .draft-mark {
            position: fixed; top: 42%; left: 8%; width: 84%; text-align: center;
            font-size: 64px; color: rgba(180, 40, 40, 0.12); letter-spacing: 8px; font-weight: bold;
            transform: rotate(-28deg); z-index: 0;
        }
        .content { position: relative; z-index: 1; }
        .kv td { padding: 1px 0; vertical-align: top; }
        .kv .k { width: 48%; color: #555; }
    </style>
</head>
<body>
    @if ($is_draft)
        <div class="draft-mark">DRAFT</div>
    @endif
    <div class="content">
    <table>
        <tr>
            <td style="width: 22%; vertical-align: top;">@include('pdf.partials.logo')</td>
            <td style="width: 56%; vertical-align: top;" class="center">
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                @if (! empty($settings['former_name']))
                    <div class="muted" style="font-size: 7.5px;">(Formerly {{ $settings['former_name'] }})</div>
                @endif
                <h2 style="margin-top: 6px;">PURCHASE ORDER</h2>
            </td>
            <td style="width: 22%; vertical-align: top;" class="right">
                <span class="badge">{{ $po->status->label() }}</span>
            </td>
        </tr>
    </table>

    {{-- Top: left parties (~65%) / right voucher + dispatch (~35%) --}}
    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 65%; vertical-align: top; padding: 0;">
                <table>
                    <tr>
                        <td class="box">
                            <div class="label">{{ $invoice_to['label'] }}</div>
                            <strong>{{ $invoice_to['name'] }}</strong>
                            @foreach ($invoice_to['lines'] as $line)<div>{!! nl2br(e($line)) !!}</div>@endforeach
                            @foreach ($invoice_to['meta'] as $line)<div>{{ $line }}</div>@endforeach
                        </td>
                    </tr>
                    <tr>
                        <td class="box">
                            <div class="label">{{ $consignee['label'] }}</div>
                            <strong>{{ $consignee['name'] }}</strong>
                            @foreach ($consignee['lines'] as $line)<div>{!! nl2br(e($line)) !!}</div>@endforeach
                            @foreach ($consignee['meta'] as $line)<div>{{ $line }}</div>@endforeach
                        </td>
                    </tr>
                    <tr>
                        <td class="box">
                            <div class="label">{{ $supplier['label'] }}</div>
                            <strong>{{ $supplier['name'] }}</strong>
                            @foreach ($supplier['lines'] as $line)<div>{!! nl2br(e($line)) !!}</div>@endforeach
                            @foreach ($supplier['meta'] as $line)<div>{{ $line }}</div>@endforeach
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 35%; vertical-align: top; padding: 0;">
                <table>
                    <tr>
                        <td class="box">
                            <div class="label">Document details</div>
                            <table class="kv">
                                @foreach ($meta as $k => $v)
                                    <tr>
                                        <td class="k">{{ $k }}</td>
                                        <td><strong>{{ $v }}</strong></td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    @if ($dispatch !== [] || $bank !== [])
                        <tr>
                            <td class="box">
                                @if ($dispatch !== [])
                                    <div class="label">Dispatch / Delivery</div>
                                    <table class="kv">
                                        @foreach ($dispatch as $k => $v)
                                            <tr>
                                                <td class="k">{{ $k }}</td>
                                                <td><strong>{{ $v }}</strong></td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif
                                @if ($bank !== [])
                                    <div class="label" style="margin-top: 4px;">Supplier bank</div>
                                    <table class="kv">
                                        @foreach ($bank as $k => $v)
                                            <tr>
                                                <td class="k">{{ $k }}</td>
                                                <td><strong>{{ $v }}</strong></td>
                                            </tr>
                                        @endforeach
                                    </table>
                                @endif
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 8px;">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 28%; text-align: left;">Description of Goods</th>
                <th style="width: 10%;">MR / Indent</th>
                <th style="width: 10%;">Brand / Make</th>
                <th style="width: 8%;">Due On</th>
                <th style="width: 8%;">Qty</th>
                <th style="width: 7%;">Rate</th>
                <th style="width: 5%;">Per</th>
                <th style="width: 5%;">Disc %</th>
                <th style="width: 9%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="center">{{ $item['sr'] }}</td>
                    <td>
                        {{ $item['description'] }}
                        @if ($item['item_code'])<br><span class="muted">{{ $item['item_code'] }}</span>@endif
                        @if ($item['hsn_sac'])<br><span class="muted">HSN/SAC: {{ $item['hsn_sac'] }}</span>@endif
                        @if ($intra)
                            <br><span class="muted">CGST {{ rtrim(rtrim($item['cgst_rate'], '0'), '.') }}% · SGST {{ rtrim(rtrim($item['sgst_rate'], '0'), '.') }}%</span>
                        @else
                            <br><span class="muted">IGST {{ rtrim(rtrim($item['igst_rate'], '0'), '.') }}%</span>
                        @endif
                    </td>
                    <td class="center">{{ $item['mr_ref'] ?: '—' }}</td>
                    <td class="center">{{ $item['brand_make'] ?: '—' }}</td>
                    <td class="center">{{ $item['due_on'] ?: '—' }}</td>
                    <td class="right">{{ $qty($item['quantity']) }} {{ $item['unit'] }}</td>
                    <td class="right">{{ $money($item['rate']) }}</td>
                    <td class="center">{{ $item['unit'] }}</td>
                    <td class="right">{{ rtrim(rtrim($item['discount_percent'], '0'), '.') ?: '0' }}</td>
                    <td class="right">{{ $money($item['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 10px;">
                <div class="label">Amount Chargeable (in words)</div>
                <div style="margin-bottom: 8px;"><strong>INR {{ str_replace('Rupees ', '', $words) }}</strong></div>
                @if ($terms)
                    <div class="label">Terms &amp; Conditions</div>
                    <div style="line-height: 1.35;">{!! nl2br(e($terms)) !!}</div>
                @endif
                @if (filled($company_pan))
                    <div style="margin-top: 10px;">Company's PAN: <strong>{{ $company_pan }}</strong></div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals" style="border: 1px solid #333;">
                    <tr><td>Taxable value</td><td class="right">{{ $money($totals['taxable_amount']) }}</td></tr>
                    @if ($nonZero($totals['discount_amount']))
                        <tr><td>Discount</td><td class="right">-{{ $money($totals['discount_amount']) }}</td></tr>
                    @endif
                    @if ($intra)
                        <tr><td>Input CGST</td><td class="right">{{ $money($totals['cgst_amount']) }}</td></tr>
                        <tr><td>Input SGST</td><td class="right">{{ $money($totals['sgst_amount']) }}</td></tr>
                    @else
                        <tr><td>Input IGST</td><td class="right">{{ $money($totals['igst_amount']) }}</td></tr>
                    @endif
                    @if ($nonZero($totals['freight_amount']))<tr><td>Freight</td><td class="right">{{ $money($totals['freight_amount']) }}</td></tr>@endif
                    @if ($nonZero($totals['other_charges']))<tr><td>Other charges</td><td class="right">{{ $money($totals['other_charges']) }}</td></tr>@endif
                    @if ($nonZero($totals['round_off']))
                        <tr><td>Round Off</td><td class="right">{{ $money($totals['round_off']) }}</td></tr>
                    @endif
                    <tr class="grand"><td>Total</td><td class="right">₹ {{ $money($totals['grand_total']) }}</td></tr>
                    <tr><td class="muted">Total Qty</td><td class="right muted">{{ $qty($totals['total_quantity']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 18px;">
        <tr>
            <td style="width: 50%;" class="muted">
                @if ($po->approved_at)
                    Approved by {{ $po->approver?->name }} on {{ $po->approved_at->format('d M Y') }}
                @endif
            </td>
            <td style="width: 50%;" class="right">
                <div class="sig center">
                    for {{ $company->legal_name ?: $company->name }}<br>
                    @if (! empty($settings['signatory_name']))
                        <strong>{{ $settings['signatory_name'] }}</strong><br>
                    @endif
                    @if (! empty($settings['signatory_designation']))
                        <span class="muted">{{ $settings['signatory_designation'] }}</span><br>
                    @endif
                    <span class="muted">Authorised Signatory</span>
                </div>
            </td>
        </tr>
    </table>
    </div>

    <div class="footer">
        {{ $po->po_number }}@if ($po->revision_no > 0) · Rev {{ $po->revision_no }}@endif
        · Page
        @if (! empty($settings['computer_generated']))
            · This is a Computer Generated Document
        @endif
    </div>
</body>
</html>
