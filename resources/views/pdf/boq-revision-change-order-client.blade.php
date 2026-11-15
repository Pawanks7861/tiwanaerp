<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $change_order->change_order_number }} Client</title>
    <style>
        @page { margin: 14mm 10mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 10px; margin: 10px 0 4px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .lines th { background: #efefef; border: 1px solid #333; padding: 3px 2px; font-size: 7px; text-transform: uppercase; }
        .lines td { border: 1px solid #666; padding: 2px; vertical-align: top; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>BOQ Revision Variation Summary</h1>
    <table class="meta">
        <tr><td><strong>Reference</strong> {{ $change_order->change_order_number }}</td><td><strong>Project</strong> {{ $project->name }}</td></tr>
        <tr><td><strong>BOQ</strong> {{ $change_order->boq_number }}</td><td><strong>Revision</strong> R{{ $change_order->fromBoq?->version }} → R{{ $change_order->toBoq?->version }}</td></tr>
        <tr><td colspan="2"><strong>Subject</strong> {{ $change_order->title }}</td></tr>
        @if($change_order->description)
            <tr><td colspan="2">{{ $change_order->description }}</td></tr>
        @endif
    </table>

    <h2>Commercial impact</h2>
    <table class="meta">
        <tr><td><strong>Contract value change</strong></td><td class="right">{{ $summary['commercial_variance'] ?? '0.00' }}</td></tr>
    </table>

    <h2>Scope changes (commercial)</h2>
    <table class="lines">
        <thead>
        <tr>
            <th>#</th><th>Change</th><th>Description</th><th>Unit</th>
            <th class="right">Prev Qty</th><th class="right">New Qty</th><th class="right">Amount Δ</th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ strtoupper($row['status'] ?? '') }}</td>
                <td>{{ $row['description'] ?? '' }}</td>
                <td>{{ $row['unit'] ?? '' }}</td>
                <td class="right">{{ $row['previous_qty'] ?? '' }}</td>
                <td class="right">{{ $row['current_qty'] ?? '' }}</td>
                <td class="right">{{ $row['commercial_difference'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>