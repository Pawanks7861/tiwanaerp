<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $order->wo_number }}</title>
    <style>
        @page { margin: 18px 20px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; line-height: 1.35; }
        h1 { font-size: 13px; margin: 0; }
        h2 { font-size: 14px; margin: 8px 0 4px; letter-spacing: 1px; text-align: center; }
        h3 { font-size: 10px; margin: 12px 0 4px; border-bottom: 1px solid #333; padding-bottom: 2px; text-transform: uppercase; letter-spacing: .3px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #555; }
        .right { text-align: right; }
        .center { text-align: center; }
        .box { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        .label { font-size: 7.5px; color: #444; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
        .lines th { background: #f0f0f0; border: 1px solid #333; padding: 4px 3px; font-size: 7.5px; text-transform: uppercase; }
        .lines td { border: 1px solid #555; padding: 4px 3px; vertical-align: top; }
        .section { page-break-inside: avoid; margin-bottom: 2px; }
        .section p, .section div.body { white-space: pre-wrap; margin: 0; }
        .sig-block { page-break-inside: avoid; margin-top: 24px; }
        .sig { border-top: 1px solid #333; display: inline-block; min-width: 170px; padding-top: 3px; margin-top: 36px; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7px; color: #666; text-align: center; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .badge { display: inline-block; padding: 1px 5px; border: 1px solid #666; font-size: 7.5px; text-transform: uppercase; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 22%; vertical-align: top;">@include('pdf.partials.logo')</td>
            <td style="width: 56%; vertical-align: top;" class="center">
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                @if ($company->address)<div class="muted">{{ $company->address }}</div>@endif
                @if ($company->email || $company->phone)
                    <div class="muted">
                        @if ($company->email){{ $company->email }}@endif
                        @if ($company->email && $company->phone) · @endif
                        @if ($company->phone){{ $company->phone }}@endif
                    </div>
                @endif
                <h2>WORK ORDER</h2>
            </td>
            <td style="width: 22%; vertical-align: top;" class="right">
                <span class="badge">{{ $order->status->label() }}</span>
            </td>
        </tr>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td class="box" style="width: 55%;">
                <div class="label">Contractor</div>
                <strong>{{ $contractor['name'] }}</strong>
                @foreach ($contractor['lines'] as $line)<div>{!! nl2br(e($line)) !!}</div>@endforeach
                @foreach ($contractor['meta'] as $line)<div>{{ $line }}</div>@endforeach
            </td>
            <td class="box" style="width: 45%;">
                <div class="label">Document</div>
                <table>
                    @foreach ($meta as $k => $v)
                        <tr>
                            <td style="width: 40%;" class="muted">{{ $k }}</td>
                            <td><strong>{{ $v }}</strong></td>
                        </tr>
                    @endforeach
                </table>
                @if ($project_block !== [])
                    <div class="label" style="margin-top: 6px;">Project / Site</div>
                    @foreach ($project_block as $k => $v)
                        <div><span class="muted">{{ $k }}:</span> {{ $v }}</div>
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    @if ($subject)
        <div class="section" style="margin-top: 10px;">
            <div class="label">Subject</div>
            <strong>{{ $subject }}</strong>
        </div>
    @endif

    @if ($sections['introduction'])
        <div class="section">
            <h3>Introduction / Scope</h3>
            <div class="body">{{ $sections['introduction'] }}</div>
        </div>
    @endif

    <h3>Rate / Scope of Work</h3>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="text-align: left;">Description of Work</th>
                <th style="width: 10%;">Unit</th>
                @unless ($hide_quantities)
                    <th style="width: 12%;">Qty</th>
                @endunless
                <th style="width: 14%;">Rate</th>
                @unless ($hide_quantities)
                    <th style="width: 14%;">Amount</th>
                @endunless
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="center">{{ $item['sr'] }}</td>
                    <td>{!! nl2br(e($item['description'])) !!}</td>
                    <td class="center">{{ $item['unit'] ?: '—' }}</td>
                    @unless ($hide_quantities)
                        <td class="right">{{ $qty($item['quantity']) }}</td>
                    @endunless
                    <td class="right">{{ $rate($item['rate']) }}</td>
                    @unless ($hide_quantities)
                        <td class="right">{{ $money($item['amount']) }}</td>
                    @endunless
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($show_totals)
        <table style="margin-top: 8px; width: 45%; margin-left: auto;">
            <tr><td class="muted">Subtotal</td><td class="right">{{ $money($totals['subtotal']) }}</td></tr>
            @if ((float) $totals['tax_amount'] != 0)
                <tr><td class="muted">GST {{ rtrim(rtrim($totals['tax_percent'], '0'), '.') }}%</td><td class="right">{{ $money($totals['tax_amount']) }}</td></tr>
            @endif
            <tr><td><strong>Total</strong></td><td class="right"><strong>₹ {{ $money($totals['total_value']) }}</strong></td></tr>
        </table>
        <div style="margin-top: 4px;"><span class="muted">Amount in words:</span> <strong>INR {{ str_replace('Rupees ', '', $words) }}</strong></div>
    @endif

    @if ($sections['contractor_scope'])
        <div class="section"><h3>Contractor Scope</h3><div class="body">{{ $sections['contractor_scope'] }}</div></div>
    @endif

    @if ($sections['company_scope'])
        <div class="section"><h3>Company Scope</h3><div class="body">{{ $sections['company_scope'] }}</div></div>
    @endif

    @if ($timeline !== [])
        <div class="section">
            <h3>Timeline</h3>
            @foreach ($timeline as $k => $v)
                <div><span class="muted">{{ $k }}:</span> {{ $v }}</div>
            @endforeach
        </div>
    @endif

    @if ($sections['payment_terms'] || $retention || $advance)
        <div class="section">
            <h3>Payment Terms</h3>
            @if ($sections['payment_terms'])<div class="body">{{ $sections['payment_terms'] }}</div>@endif
            @if ($advance)<div style="margin-top: 3px;"><span class="muted">Mobilisation advance:</span> ₹ {{ $advance }}</div>@endif
            @if ($retention)<div style="margin-top: 3px;">{{ $retention }}</div>@endif
        </div>
    @endif

    @if ($penalty)
        <div class="section"><h3>Penalty / Liquidated Damages</h3><div class="body">{{ $penalty }}</div></div>
    @endif

    @if ($sections['safety_terms'])
        <div class="section"><h3>Safety / Compliance</h3><div class="body">{{ $sections['safety_terms'] }}</div></div>
    @endif

    @if ($sections['general_terms'])
        <div class="section"><h3>General Terms &amp; Conditions</h3><div class="body">{{ $sections['general_terms'] }}</div></div>
    @endif

    <table class="sig-block">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div class="label">For {{ $company->legal_name ?: $company->name }}</div>
                <div class="sig">
                    @if (! empty($settings['signatory_name']))
                        <strong>{{ $settings['signatory_name'] }}</strong><br>
                    @endif
                    @if (! empty($settings['signatory_designation']))
                        <span class="muted">{{ $settings['signatory_designation'] }}</span><br>
                    @endif
                    <span class="muted">Authorised Signatory</span>
                </div>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <div class="label">Accepted &amp; Agreed by Contractor</div>
                <div class="sig">
                    <strong>{{ $contractor['name'] }}</strong><br>
                    <span class="muted">Authorised Signatory</span><br>
                    <span class="muted">Date: ____________________</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $order->wo_number }}
        @if (! empty($settings['computer_generated']))
            · This is a Computer Generated Document
        @endif
    </div>
</body>
</html>
