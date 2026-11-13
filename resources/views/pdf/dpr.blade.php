<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $dpr->dpr_number }}</title>
    <style>
        @page { margin: 16px 18px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7.5px; color: #1e293b; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 11px; margin: 0 0 2px; letter-spacing: 1px; }
        h3 { font-size: 8.5px; margin: 10px 0 3px; text-transform: uppercase; letter-spacing: .5px; color: #334155; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        .box { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; }
        .label { font-size: 7px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .lines th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 3px 2px; font-size: 6.5px; text-transform: uppercase; }
        .lines td { border: 1px solid #e2e8f0; padding: 3px 2px; vertical-align: top; }
        .badge { display: inline-block; padding: 2px 5px; border: 1px solid #94a3b8; border-radius: 3px; font-size: 7px; text-transform: uppercase; }
        .hold { background: #fef3c7; }
        .draft { position: fixed; top: 40%; left: 15%; font-size: 60px; color: #e2e8f0; transform: rotate(-30deg); z-index: -1; }
        .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7px; color: #94a3b8; }
    </style>
</head>
<body>
    @php($approved = $dpr->status === \App\Enums\SiteExecution\DprStatus::Approved)
    @unless ($approved)<div class="draft">NOT APPROVED</div>@endunless

    <table>
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @include('pdf.partials.logo')
                <h1>{{ $company->legal_name ?: $company->name }}</h1>
                <div class="muted" style="margin-top: 2px; line-height: 1.35;">
                    {!! nl2br(e($company->address)) !!}@if ($company->city)<br>{{ $company->city }}@if ($company->pincode) - {{ $company->pincode }}@endif @endif
                </div>
            </td>
            <td style="width: 45%; vertical-align: top;" class="right">
                <h2>DAILY PROGRESS REPORT</h2>
                <div style="font-size: 11px; font-weight: bold;">{{ $dpr->dpr_number }}@if ($dpr->revision > 0) <span class="badge">Rev {{ $dpr->revision }}</span>@endif</div>
                <div class="muted" style="margin-top: 2px;">Date: {{ $dpr->dpr_date?->format('d M Y') }}</div>
                <div style="margin-top: 2px;"><span class="badge">{{ $dpr->status->label() }}</span></div>
                <div class="muted" style="margin-top: 2px;">Source: {{ ($dpr->source ?? 'direct') === 'legacy_site_diary' ? 'Site diary (legacy)' : 'Direct entry' }}</div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td class="box" style="width: 35%;">
                <div class="label">Project</div>
                <strong>{{ $project->name }}</strong><br><span class="muted">{{ $project->code }}</span>
            </td>
            <td class="box" style="width: 20%;">
                <div class="label">Engineer</div>
                {{ $dpr->engineer?->name ?? '—' }}
            </td>
            <td class="box" style="width: 15%;">
                <div class="label">Department</div>
                {{ $dpr->department ?: '—' }}
            </td>
            <td class="box" style="width: 15%;">
                <div class="label">Agency</div>
                {{ $dpr->agency_name ?: '—' }}
            </td>
            <td class="box" style="width: 15%;">
                <div class="label">Weather</div>
                {{ $dpr->weather ?: '—' }}
            </td>
        </tr>
    </table>

    @if ($dpr->work_performed)
        <h3>Work performed (summary)</h3>
        <div class="box" style="line-height: 1.35;">{!! nl2br(e($dpr->work_performed)) !!}</div>
    @endif

    <h3>Activity progress</h3>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 2%;">#</th>
                <th style="width: 9%; text-align: left;">Work area</th>
                <th style="width: 16%; text-align: left;">Activity</th>
                <th style="width: 7%;">Plan dates</th>
                <th>Unit</th>
                <th>Total</th>
                <th>Previous</th>
                <th>Target</th>
                <th>Today</th>
                <th>Hold</th>
                <th>Cumul.</th>
                <th>Balance</th>
                <th>% done</th>
                <th>% ach.</th>
                <th>Shortfall</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i => $item)
                <tr @class(['hold' => $item->on_hold])>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $item->work_area ?: '—' }}</td>
                    <td>
                        @if ($item->task)<strong>{{ $item->task->wbs_code }}</strong> {{ $item->task->name }}@else{{ $item->activity_name_snapshot ?? $item->description }}@endif
                        @if ($item->hold_reason)<br><span class="muted">Hold: {{ $item->hold_reason }}</span>@endif
                    </td>
                    <td class="center muted">
                        @if ($item->planned_start_date){{ $item->planned_start_date->format('d/m') }}@endif
                        @if ($item->planned_end_date)–{{ $item->planned_end_date->format('d/m') }}@endif
                        @if (! $item->planned_start_date && ! $item->planned_end_date)—@endif
                    </td>
                    <td class="center">{{ $item->unit?->symbol ?? $item->unit_snapshot }}</td>
                    <td class="right">{{ $qty($item->planned_qty) }}</td>
                    <td class="right">{{ $qty($item->previous_executed_qty) }}</td>
                    <td class="right">{{ $qty($item->today_target_qty) }}</td>
                    <td class="right"><strong>{{ $qty($item->executed_qty) }}</strong></td>
                    <td class="right">{{ $qty($item->hold_quantity) }}</td>
                    <td class="right">{{ $qty($item->cumulative_qty) }}</td>
                    <td class="right">{{ $qty($item->balance_qty) }}</td>
                    <td class="right">{{ $pct($item->completion_percent) }}</td>
                    <td class="right">{{ $pct($item->achievement_percent) }}</td>
                    <td class="muted" style="font-size: 6.5px;">{{ $item->shortfall_reason ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="15" class="center muted">No work recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top: 4px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 4px;">
                <h3>Manpower</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Trade</th>
                            <th style="text-align: left;">Agency</th>
                            <th>HC</th>
                            <th>Skilled</th>
                            <th>Unskilled</th>
                            <th>Hrs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($labours as $l)
                            <tr>
                                <td>{{ $l->trade?->name }}</td>
                                <td>{{ $l->subcontractor?->name ?? 'Own' }}</td>
                                <td class="right">{{ $l->headcount }}</td>
                                <td class="right">{{ $l->skilled ?? '—' }}</td>
                                <td class="right">{{ $l->unskilled ?? '—' }}</td>
                                <td class="right">{{ $l->hours }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="center muted">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 4px;">
                <h3>Equipment</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th style="text-align: left;">Equipment</th>
                            <th style="text-align: left;">Operator</th>
                            <th>Time</th>
                            <th>Work h</th>
                            <th>Idle h</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($equipment as $e)
                            <tr>
                                <td>{{ collect([$e->equipmentType?->name, $e->description])->filter()->implode(' · ') }}</td>
                                <td>{{ $e->operator_name ?: '—' }}</td>
                                <td class="center muted">
                                    @if ($e->start_time){{ substr((string) $e->start_time, 0, 5) }}@endif
                                    @if ($e->end_time)–{{ substr((string) $e->end_time, 0, 5) }}@endif
                                </td>
                                <td class="right">{{ $e->working_hours }}</td>
                                <td class="right">{{ $e->idle_hours }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="center muted">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <h3>Materials (opening / received / issued / closing)</h3>
    <table class="lines">
        <thead>
            <tr>
                <th style="text-align: left;">Item</th>
                <th>Opening</th>
                <th>Received</th>
                <th>Issued</th>
                <th>Closing</th>
                <th>Unit</th>
                <th style="text-align: left;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($materials as $m)
                <tr>
                    <td>{{ $m->material?->name }} <span class="muted">{{ $m->material?->code }}</span></td>
                    <td class="right">{{ $qty($m->opening_qty) }}</td>
                    <td class="right">{{ $qty($m->received_qty) }}</td>
                    <td class="right">{{ $qty($m->issued_qty ?? $m->quantity) }}</td>
                    <td class="right">{{ $qty($m->closing_qty) }}</td>
                    <td class="center">{{ $m->unit?->symbol }}</td>
                    <td>{{ $m->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="center muted">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (($constraints ?? collect())->isNotEmpty() || $dpr->constraints_summary)
        <h3>Constraints / hindrances</h3>
        @if ($dpr->constraints_summary)
            <div class="box" style="margin-bottom: 4px; line-height: 1.35;">{!! nl2br(e($dpr->constraints_summary)) !!}</div>
        @endif
        @if (($constraints ?? collect())->isNotEmpty())
            <table class="lines">
                <thead>
                    <tr>
                        <th style="text-align: left;">Category</th>
                        <th style="text-align: left;">Description</th>
                        <th style="text-align: left;">Area</th>
                        <th>Responsible</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($constraints as $c)
                        <tr>
                            <td>{{ $c->category }}</td>
                            <td>{{ $c->description }}</td>
                            <td>{{ $c->work_area ?: ($c->task ? trim($c->task->wbs_code.' '.$c->task->name) : '—') }}</td>
                            <td>{{ $c->responsible ?: '—' }}</td>
                            <td class="center">{{ $c->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($dpr->site_issues)
        <h3>Site issues</h3>
        <div class="box" style="line-height: 1.35;">{!! nl2br(e($dpr->site_issues)) !!}</div>
    @endif
    @if ($dpr->remarks)
        <h3>Remarks</h3>
        <div class="box" style="line-height: 1.35;">{!! nl2br(e($dpr->remarks)) !!}</div>
    @endif

    <table style="margin-top: 18px;">
        <tr>
            <td style="width: 55%;" class="muted">
                @if ($approved)Approved by {{ $dpr->approver?->name }} on {{ $dpr->approved_at?->format('d M Y H:i') }}@else Not yet approved — figures may change. @endif
            </td>
            <td style="width: 45%;" class="right">
                <div style="margin-top: 20px; border-top: 1px solid #94a3b8; display: inline-block; padding-top: 3px; min-width: 160px;" class="center">
                    Site engineer / Project manager
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">{{ $dpr->dpr_number }} · Generated {{ now()->format('d M Y H:i') }} · Snapshots as stored on the DPR.</div>
</body>
</html>
