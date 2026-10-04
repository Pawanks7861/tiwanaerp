<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22px 22px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5px; color: #1e293b; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 11px; margin: 10px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .cards td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; }
        .cards .label { font-size: 6.5px; color: #64748b; text-transform: uppercase; }
        .cards .value { font-size: 9.5px; font-weight: bold; }
        .lines th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 3px 2px; font-size: 6.5px; text-transform: uppercase; text-align: left; }
        .lines th.right { text-align: right; }
        .lines td { border: 1px solid #e2e8f0; padding: 2.5px 2px; vertical-align: top; }
        .lines tr.total td { font-weight: bold; background: #f8fafc; border-top: 1.2px solid #1e293b; }
        .flag { color: #b45309; font-size: 6.5px; }
        .notes { margin-top: 8px; color: #64748b; font-size: 6.8px; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 6.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="footer">{{ $company }} · {{ $title }} · Generated {{ $generated_at }} by {{ $generated_by }}</div>

    <table class="meta">
        <tr>
            <td style="width: 60%;">
                @include('pdf.partials.logo')
                <div class="muted">{{ $company }}</div>
                <h1>{{ $title }}</h1>
                <div style="margin-top: 2px;">{{ $project }}</div>
            </td>
            <td style="width: 40%;" class="right">
                <div><strong>{{ $period }}</strong></div>
                <div class="muted">Generated {{ $generated_at }}</div>
                <div class="muted">by {{ $generated_by }}</div>
            </td>
        </tr>
    </table>
    <div class="muted" style="margin-top: 3px;">Filters: {{ $filters === [] ? 'None' : implode('; ', $filters) }}</div>

    @if (count($result->cards))
        <table class="cards" style="margin-top: 8px;">
            <tr>
                @foreach ($result->cards as $card)
                    <td>
                        <div class="label">{{ $card['label'] }}</div>
                        <div class="value">{{ $cell(['type' => $card['type'] ?? 'text'], $card['value']) }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @php($tables = array_merge([['title' => null, 'columns' => $result->columns, 'rows' => $result->rows, 'totals' => $result->totals]], $result->sections))
    @foreach ($tables as $table)
        @if ($table['title'])<h2>{{ $table['title'] }}</h2>@endif
        <table class="lines" style="margin-top: 8px;">
            <thead>
                <tr>
                    @foreach ($table['columns'] as $column)
                        <th class="{{ ($column['align'] ?? '') === 'right' ? 'right' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr>
                        @foreach ($table['columns'] as $i => $column)
                            <td class="{{ ($column['align'] ?? '') === 'right' ? 'right' : '' }}">
                                {{ $cell($column, $row[$column['key']] ?? null) }}
                                @if ($i === 0 && ! empty($row['flag']))<div class="flag">{{ $row['flag'] }}</div>@endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($table['columns'])) }}" class="muted">No records for these filters.</td></tr>
                @endforelse
                @if (! empty($table['totals']))
                    <tr class="total">
                        @foreach ($table['columns'] as $column)
                            @php($v = $table['totals'][$column['key']] ?? null)
                            <td class="{{ ($column['align'] ?? '') === 'right' ? 'right' : '' }}">{{ $v === null ? '' : $cell($column, $v) }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach

    @if (count($result->notes))
        <div class="notes">
            @foreach ($result->notes as $note)<div>• {{ $note }}</div>@endforeach
        </div>
    @endif
</body>
</html>
