<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $pass->pass_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
    @include('pdf.partials.logo')
    <h1>{{ $company->legal_name ?: $company->name }}</h1>
    <p class="muted">{{ $project->name }}@if($pass->site) · {{ $pass->site->name }}@endif</p>
    <h2>Gate Pass {{ $pass->pass_number }}</h2>
    <p>
        {{ strtoupper($pass->direction->value) }}
        · {{ $pass->passed_at?->format('d M Y H:i') }}
        · {{ $pass->returnable ? 'Returnable' : 'Non-returnable' }}
        · {{ $pass->status->label() }}
    </p>
    <p>Party: {{ $pass->party_name ?: '—' }} · Vehicle: {{ $pass->vehicle_no ?: '—' }} · Driver: {{ $pass->driver_name ?: '—' }} {{ $pass->driver_mobile }}</p>
    <p>Purpose: {{ $pass->purpose }}</p>
    <table>
        <thead>
            <tr><th>Type</th><th>Description</th><th>Qty</th><th>Weight</th><th>Destination</th></tr>
        </thead>
        <tbody>
            @foreach ($pass->items as $item)
                <tr>
                    <td>{{ $item->item_type->label() }}</td>
                    <td>{{ $item->description }}@if($item->scrap_type) ({{ $item->scrap_type }})@endif</td>
                    <td>{{ $item->quantity }} {{ $item->unit?->symbol }}</td>
                    <td>{{ $item->weight }}</td>
                    <td>{{ $item->destination }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($pass->weighbridgeReceipt)
        <p>Weighbridge {{ $pass->weighbridgeReceipt->slip_no }}: gross {{ $pass->weighbridgeReceipt->gross_weight }} / tare {{ $pass->weighbridgeReceipt->tare_weight }} / net {{ $pass->weighbridgeReceipt->net_weight }} {{ $pass->weighbridgeReceipt->weight_unit }}</p>
    @endif
    <p>Approved: {{ $pass->approver?->name ?: '—' }} · Security verified: {{ $pass->verifier?->name ?: '—' }}</p>
</body>
</html>
