<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $dpr->dpr_number }}</title>
    <style>
        @page { margin: 22px 26px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 13px; margin: 0 0 2px; letter-spacing: 1px; }
        h3 { font-size: 10px; margin: 12px 0 4px; text-transform: uppercase; letter-spacing: .5px; color: #334155; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .box { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        .label { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .lines th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 4px 3px; font-size: 7.5px; text-transform: uppercase; }
        .lines td { border: 1px solid #e2e8f0; padding: 4px 3px; vertical-align: top; }
        .badge { display: inline-block; padding: 2px 6px; border: 1px solid #94a3b8; border-radius: 3px; font-size: 8px; text-transform: uppercase; }
        .draft { position: fixed; top: 40%; left: 15%; font-size: 70px; color: #e2e8f0; transform: rotate(-30deg); z-index: -1; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; }
    </style>
</head>
<body>
    @php($approved = $dpr->status === \App\Enums\SiteExecution\DprStatus::Approved)
    @unless ($approved)<div class="draft">NOT APPROVED</div>@endunless

    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                @include('pdf.partials.logo')
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                <div class="muted" style="margin-top: 3px; line-height: 1.4;">
                    {!! nl2br(e($company->address)) !!}@if ($company->city)<br>{{ $company->city }}@if ($company->pincode) - {{ $company->pincode }}@endif @endif
                    @if ($company->phone || $company->email)<br>{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}@endif
                </div>
            </td>
            <td style="width: 40%; vertical-align: top;" class="right">
                <h2>DAILY PROGRESS REPORT</h2>
                <div style="font-size: 12px; font-weight: bold;">{{ $dpr->dpr_number }}@if ($dpr->revision > 0) <span class="badge">Rev {{ $dpr->revision }}</span>@endif</div>
                <div class="muted" style="margin-top: 3px;">Date: {{ $dpr->dpr_date?->format('d M Y') }}</div>
                <div style="margin-top: 3px;"><span class="badge">{{ $dpr->status->label() }}</span></div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 10px;">
        <tr>
            <td class="box" style="width: 40%;">
                <div class="label">Project</div>
                <strong>{{ $project->name }}</strong><br><span class="muted">{{ $project->code }} · {{ $project->project_number }}</span>
                @if ($project->city)<br>{{ $project->city }}@endif
            </td>
            <td class="box" style="width: 30%;">
                <div class="label">Site engineer</div>
                {{ $dpr->engineer?->name ?? '—' }}
            </td>
            <td class="box" style="width: 30%;">
                <div class="label">Weather</div>
                {{ $dpr->weather ?: '—' }}
            </td>
        </tr>
    </table>

    <h3>Work done</h3>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 31%; text-align: left;">Activity / task</th>
                <th style="width: 18%; text-align: left;">BOQ item</th>
                <th>Unit</th>
                <th>Planned</th>
                <th>Today</th>
                <th>Cumulative</th>
                <th>Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i => $item)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>
                        @if ($item->task)<strong>{{ $item->task->wbs_code }}</strong> {{ $item->task->name }}@else{{ $item->description }}@endif
                        @if ($item->task && $item->description && $item->description !== $item->task->name)<br><span class="muted">{{ $item->description }}</span>@endif
                    </td>
                    <td>{{ $item->boqItem ? trim($item->boqItem->item_code.' '.$item->boqItem->name) : '—' }}</td>
                    <td class="center">{{ $item->unit?->symbol }}</td>
                    <td class="right">{{ $qty($item->planned_qty) }}</td>
                    <td class="right"><strong>{{ $qty($item->executed_qty) }}</strong></td>
                    <td class="right">{{ $qty($item->cumulative_qty) }}</td>
                    <td class="right">{{ $qty($item->balance_qty) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center muted">No work recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 4px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 6px;">
                <h3>Labour</h3>
                <table class="lines">
                    <thead><tr><th style="text-align: left;">Trade</th><th style="text-align: left;">Subcontractor</th><th>Headcount</th><th>Hours</th></tr></thead>
                    <tbody>
                        @forelse ($labours as $l)
                            <tr><td>{{ $l->trade?->name }}</td><td>{{ $l->subcontractor?->name ?? 'Own' }}</td><td class="right">{{ $l->headcount }}</td><td class="right">{{ $l->hours }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="center muted">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 6px;">
                <h3>Equipment</h3>
                <table class="lines">
                    <thead><tr><th style="text-align: left;">Equipment</th><th>Working h</th><th>Idle h</th></tr></thead>
                    <tbody>
                        @forelse ($equipment as $e)
                            <tr><td>{{ collect([$e->equipmentType?->name, $e->description])->filter()->implode(' · ') }}</td><td class="right">{{ $e->working_hours }}</td><td class="right">{{ $e->idle_hours }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="center muted">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <h3>Material used (reported, not a stock posting)</h3>
    <table class="lines">
        <thead><tr><th style="text-align: left;">Item</th><th>Quantity</th><th>Unit</th><th style="text-align: left;">Remarks</th></tr></thead>
        <tbody>
            @forelse ($materials as $m)
                <tr><td>{{ $m->material?->name }} <span class="muted">{{ $m->material?->code }}</span></td><td class="right">{{ $qty($m->quantity) }}</td><td class="center">{{ $m->unit?->symbol }}</td><td>{{ $m->remarks }}</td></tr>
            @empty
                <tr><td colspan="4" class="center muted">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($dpr->site_issues)
        <h3>Site issues</h3>
        <div class="box" style="line-height: 1.4;">{!! nl2br(e($dpr->site_issues)) !!}</div>
    @endif
    @if ($dpr->remarks)
        <h3>Remarks</h3>
        <div class="box" style="line-height: 1.4;">{!! nl2br(e($dpr->remarks)) !!}</div>
    @endif

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 50%;" class="muted">
                @if ($approved)Approved by {{ $dpr->approver?->name }} on {{ $dpr->approved_at?->format('d M Y H:i') }}@else Not yet approved — figures may change. @endif
            </td>
            <td style="width: 50%;" class="right">
                <div style="margin-top: 26px; border-top: 1px solid #94a3b8; display: inline-block; padding-top: 3px; min-width: 180px;" class="center">
                    Site engineer / Project manager
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $dpr->dpr_number }}@if ($dpr->revision > 0) · Revision {{ $dpr->revision }}@endif · Generated {{ now()->format('d M Y H:i') }} · Quantities as stored on the DPR.</div>
</body>
</html>
