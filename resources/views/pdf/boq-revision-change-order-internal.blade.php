<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $change_order->change_order_number }} Internal</title>
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
        .confidential { color: #8a1f1f; font-weight: bold; font-size: 8px; }
    </style>
</head>
<body>
    <p class="confidential">CONFIDENTIAL — INTERNAL COSTING</p>
    <h1>BOQ Revision Change Order</h1>
    <table class="meta">
        <tr><td><strong>CO No.</strong> {{ $change_order->change_order_number }}</td><td><strong>Project</strong> {{ $project->name }}</td></tr>
        <tr><td><strong>BOQ</strong> {{ $change_order->boq_number }}</td><td><strong>Status</strong> {{ $change_order->status->label() }}</td></tr>
        <tr><td><strong>From</strong> R{{ $change_order->fromBoq?->version }}</td><td><strong>To</strong> R{{ $change_order->toBoq?->version }}</td></tr>
        <tr><td colspan="2"><strong>Title</strong> {{ $change_order->title }}</td></tr>
    </table>

    <h2>Summary</h2>
    <table class="meta">
        <tr>
            <td>Commercial Δ {{ $summary['commercial_variance'] ?? '0.00' }}</td>
            <td>Internal Δ {{ $summary['internal_variance'] ?? '—' }}</td>
            <td>Margin Δ {{ $summary['margin_variance'] ?? '—' }}</td>
        </tr>
        <tr>
            <td>Added {{ $summary['added'] ?? 0 }}</td>
            <td>Removed {{ $summary['removed'] ?? 0 }}</td>
            <td>Modified {{ $summary['modified'] ?? 0 }}</td>
        </tr>
    </table>

    <h2>Line changes</h2>
    <table class="lines">
        <thead>
        <tr>
            <th>#</th><th>Status</th><th>Code</th><th>Description</th><th>Unit</th>
            <th class="right">Qty Δ</th><th class="right">Commercial Δ</th><th class="right">Internal Δ</th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ strtoupper($row['status'] ?? '') }}</td>
                <td>{{ $row['item_code'] ?? '' }}</td>
                <td>{{ $row['description'] ?? '' }}</td>
                <td>{{ $row['unit'] ?? '' }}</td>
                <td class="right">{{ $row['qty_difference'] ?? '' }}</td>
                <td class="right">{{ $row['commercial_difference'] ?? '' }}</td>
                <td class="right">{{ $row['internal_cost_difference'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>