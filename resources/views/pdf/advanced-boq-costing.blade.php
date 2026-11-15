<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $boq->boq_number }} Internal Costing</title>
    <style>
        @page { margin: 14mm 10mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        h1 { font-size: 14px; margin: 0; letter-spacing: 0.8px; }
        h2 { font-size: 11px; margin: 12px 0 4px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        h3 { font-size: 9px; margin: 8px 0 3px; }
        .muted { color: #555; }
        .right { text-align: right; }
        .center { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 1px 4px; vertical-align: top; }
        .lines th { background: #efefef; border: 1px solid #333; padding: 3px 2px; font-size: 7px; text-transform: uppercase; }
        .lines td { border: 1px solid #666; padding: 2px; vertical-align: top; }
        .totals td { border: 1px solid #333; padding: 3px; font-weight: bold; background: #f7f7f7; }
        .badge { display: inline-block; padding: 1px 5px; border: 1px solid #666; font-size: 7px; text-transform: uppercase; }
        .confidential { color: #8a1f1f; font-weight: bold; letter-spacing: 0.6px; font-size: 8px; }
        .exec td { border: 1px solid #333; padding: 5px 6px; }
        .exec .label { font-size: 7px; text-transform: uppercase; color: #555; }
        .exec .value { font-size: 11px; font-weight: bold; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .section-break { page-break-before: always; }
        .footer {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            font-size: 6.5px; color: #555; text-align: center;
            border-top: 0.5px solid #999; padding-top: 2px;
        }
        .draft-mark, .internal-mark {
            position: fixed; top: 38%; left: 6%; width: 88%; text-align: center;
            font-weight: bold; transform: rotate(-28deg); z-index: 0;
        }
        .draft-mark { font-size: 58px; color: rgba(180, 40, 40, 0.10); letter-spacing: 8px; }
        .internal-mark { font-size: 42px; color: rgba(40, 60, 120, 0.07); letter-spacing: 4px; top: 52%; }
        .content { position: relative; z-index: 1; }
        .sig { border-top: 1px solid #333; display: inline-block; min-width: 140px; padding-top: 3px; margin-top: 36px; }
    </style>
</head>
<body>
    @if ($is_draft)
        <div class="draft-mark">{{ !empty($is_review) ? 'DRAFT / REVIEW' : 'DRAFT' }}</div>
    @endif
    <div class="internal-mark">INTERNAL COSTING</div>

    <div class="footer">
        {{ $company->legal_name ?: $company->name }} · {{ $project->name }} · {{ $boq->boq_number }} R{{ $boq->version }}
        · <span class="confidential">INTERNAL / CONFIDENTIAL</span><br>
        {{ $footer_note }}
    </div>

    <div class="content">
        <table>
            <tr>
                <td style="width: 18%; vertical-align: top;">@include('pdf.partials.logo')</td>
                <td style="width: 64%;" class="center">
                    <div style="font-size: 12px; font-weight: bold;">{{ $company->legal_name ?: $company->name }}</div>
                    @if (! empty($settings['former_name']))
                        <div class="muted" style="font-size: 7px;">(Formerly {{ $settings['former_name'] }})</div>
                    @endif
                    <h1 style="margin-top: 6px;">{{ $title }}</h1>
                    <div class="muted">{{ $subtitle }}</div>
                    <div class="confidential" style="margin-top: 4px;">INTERNAL / CONFIDENTIAL</div>
                </td>
                <td style="width: 18%;" class="right">
                    <span class="badge">{{ $boq->status->label() }}</span><br>
                    <span class="badge" style="margin-top: 3px;">R{{ $boq->version }}</span>
                </td>
            </tr>
        </table>

        <table class="meta" style="margin-top: 8px;">
            @foreach (collect($meta)->filter(fn ($v) => filled($v))->chunk(2) as $chunk)
                <tr>
                    @foreach ($chunk as $label => $value)
                        <td style="width: 16%;" class="muted">{{ $label }}</td>
                        <td style="width: 34%;">{{ $value }}</td>
                    @endforeach
                    @if ($chunk->count() === 1)
                        <td style="width: 16%;"></td>
                        <td style="width: 34%;"></td>
                    @endif
                </tr>
            @endforeach
        </table>

        @if ($sections['cost_summary'] ?? true)
            <h2>Executive Cost Summary</h2>
            <table class="exec">
                <tr>
                    <td style="width: 25%;">
                        <div class="label">Material Cost (A)</div>
                        <div class="value">₹ {{ $executive['material_a_fmt'] }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">Labour Cost (B)</div>
                        <div class="value">₹ {{ $executive['labour_b_fmt'] }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">Equipment Cost (C)</div>
                        <div class="value">₹ {{ $executive['equipment_c_fmt'] }}</div>
                    </td>
                    <td style="width: 25%;">
                        <div class="label">Other Cost (D)</div>
                        <div class="value">₹ {{ $executive['other_d_fmt'] }}</div>
                    </td>
                </tr>
                <tr>
                    <td>
                        <div class="label">Grand Internal Cost</div>
                        <div class="value">₹ {{ $executive['grand_internal_fmt'] }}</div>
                    </td>
                    <td>
                        <div class="label">Commercial BOQ Value</div>
                        <div class="value">₹ {{ $executive['commercial_fmt'] }}</div>
                    </td>
                    <td>
                        <div class="label">Estimated Margin</div>
                        <div class="value">₹ {{ $executive['margin_fmt'] }}</div>
                    </td>
                    <td>
                        <div class="label">Margin %</div>
                        <div class="value">{{ $executive['margin_pct_fmt'] }}</div>
                    </td>
                </tr>
            </table>

            <h2>BOQ Cost Summary</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Section</th>
                        <th>Item</th>
                        <th>Unit</th>
                        <th class="right">Qty</th>
                        <th class="right">Material</th>
                        <th class="right">Labour</th>
                        <th class="right">Equip.</th>
                        <th class="right">Other</th>
                        <th class="right">Internal</th>
                        <th class="right">Int. Rate</th>
                        <th class="right">Client Rate</th>
                        <th class="right">Commercial</th>
                        <th class="right">Margin</th>
                        <th class="right">M%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($item_rows as $row)
                        <tr>
                            <td>{{ $row['sr'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td>{{ $row['description'] }}</td>
                            <td>{{ $row['unit'] }}</td>
                            <td class="right">{{ $row['qty_fmt'] }}</td>
                            <td class="right">{{ $row['material_fmt'] }}</td>
                            <td class="right">{{ $row['labour_fmt'] }}</td>
                            <td class="right">{{ $row['equipment_fmt'] }}</td>
                            <td class="right">{{ $row['other_fmt'] }}</td>
                            <td class="right">{{ $row['internal_fmt'] }}</td>
                            <td class="right">{{ $row['internal_rate_fmt'] }}</td>
                            <td class="right">{{ $row['client_rate_fmt'] }}</td>
                            <td class="right">{{ $row['commercial_fmt'] }}</td>
                            <td class="right">{{ $row['margin_fmt'] }}</td>
                            <td class="right">{{ $row['margin_pct_fmt'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (! empty($section_totals))
                <h3>Section Totals</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th class="right">Material</th>
                            <th class="right">Labour</th>
                            <th class="right">Other</th>
                            <th class="right">Internal</th>
                            <th class="right">Commercial</th>
                            <th class="right">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section_totals as $sec)
                            <tr class="totals">
                                <td>{{ $sec['section'] }}</td>
                                <td class="right">{{ $sec['material_fmt'] }}</td>
                                <td class="right">{{ $sec['labour_fmt'] }}</td>
                                <td class="right">{{ $sec['other_fmt'] }}</td>
                                <td class="right">{{ $sec['internal_fmt'] }}</td>
                                <td class="right">{{ $sec['commercial_fmt'] }}</td>
                                <td class="right">{{ $sec['margin_fmt'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        @if (($sections['measurements'] ?? false) && ! empty($measurements))
            <div class="section-break"></div>
            <h2>Measurement Abstract</h2>
            @foreach ($measurements as $block)
                <h3>{{ $block['item'] }} <span class="muted">({{ $block['unit'] }} · Total {{ $block['total_fmt'] }})</span></h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Unit</th>
                            <th class="right">Nos</th>
                            <th class="right">L</th>
                            <th class="right">W</th>
                            <th class="right">H/D</th>
                            <th>Formula</th>
                            <th class="right">Qty</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['lines'] as $line)
                            <tr>
                                <td>{{ $line['description'] }}</td>
                                <td>{{ $line['unit'] }}</td>
                                <td class="right">{{ $line['nos'] }}</td>
                                <td class="right">{{ $line['length'] }}</td>
                                <td class="right">{{ $line['width'] }}</td>
                                <td class="right">{{ $line['height_depth'] }}</td>
                                <td>{{ $line['formula'] }}</td>
                                <td class="right">{{ $line['calculated_qty_fmt'] }}</td>
                                <td>{{ $line['remarks'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @endif

        @if (($sections['material_consumption'] ?? false) && ! empty($material_consumption))
            <div class="section-break"></div>
            <h2>Material Consumption</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>BOQ Item</th>
                        <th>Material</th>
                        <th class="right">BOQ Qty</th>
                        <th class="right">Cons./Unit</th>
                        <th class="right">Gross</th>
                        <th class="right">Wastage %</th>
                        <th class="right">Net</th>
                        <th>Unit</th>
                        <th class="right">Rate</th>
                        <th class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($material_consumption as $row)
                        <tr>
                            <td>{{ $row['item'] }}</td>
                            <td>{{ $row['material'] }}</td>
                            <td class="right">{{ $row['boq_qty_fmt'] }}</td>
                            <td class="right">{{ $row['consumption_fmt'] }}</td>
                            <td class="right">{{ $row['gross_fmt'] }}</td>
                            <td class="right">{{ $row['wastage_percent'] }}</td>
                            <td class="right">{{ $row['net_fmt'] }}</td>
                            <td>{{ $row['unit'] }}</td>
                            <td class="right">{{ $row['rate_fmt'] }}</td>
                            <td class="right">{{ $row['amount_fmt'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['material_summary'] ?? false) && ! empty($material_summary))
            <h2>Material Requirement Summary</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Unit</th>
                        <th class="right">Gross</th>
                        <th class="right">Wastage</th>
                        <th class="right">Net</th>
                        <th class="right">Weighted Rate</th>
                        <th class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($material_summary as $row)
                        <tr>
                            <td>{{ $row['material'] }}</td>
                            <td>{{ $row['unit'] }}</td>
                            <td class="right">{{ $row['gross_fmt'] }}</td>
                            <td class="right">{{ $row['wastage_fmt'] }}</td>
                            <td class="right">{{ $row['net_fmt'] }}</td>
                            <td class="right">{{ $row['weighted_rate_fmt'] }}</td>
                            <td class="right">{{ $row['amount_fmt'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['labour_summary'] ?? false) && ! empty($labour_summary))
            <h2>Labour Cost Summary</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Activity</th>
                        <th>BOQ Item</th>
                        <th>Unit</th>
                        <th class="right">Qty</th>
                        <th class="right">Rate</th>
                        <th class="right">Amount</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($labour_summary as $row)
                        <tr>
                            <td>{{ $row['activity'] }}</td>
                            <td>{{ $row['item'] }}</td>
                            <td>{{ $row['unit'] }}</td>
                            <td class="right">{{ $row['quantity_fmt'] }}</td>
                            <td class="right">{{ $row['rate_fmt'] }}</td>
                            <td class="right">{{ $row['amount_fmt'] }}</td>
                            <td>{{ $row['is_steel_fixing'] ? 'Steel fixing labour' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['bbs'] ?? false) && ! empty($bbs))
            <div class="section-break"></div>
            <h2>Bar Bending Schedule / Reinforcement Summary</h2>
            @foreach ($bbs as $block)
                <h3>{{ $block['item'] }} — {{ $block['title'] }}
                    <span class="muted">(Wastage {{ $block['wastage_percent'] }}% · Net {{ $block['required_weight_mt_fmt'] }} MT)</span>
                </h3>
                @if ($mode === 'detailed')
                    <table class="lines">
                        <thead>
                            <tr>
                                <th>Element</th>
                                <th>Mark</th>
                                <th class="right">Dia</th>
                                <th class="right">Mem</th>
                                <th class="right">Bars</th>
                                <th class="right">Tot</th>
                                <th class="right">A</th>
                                <th class="right">B</th>
                                <th class="right">C</th>
                                <th class="right">D</th>
                                <th class="right">Hook</th>
                                <th class="right">Ded</th>
                                <th class="right">Cut</th>
                                <th class="right">Length</th>
                                <th class="right">UW</th>
                                <th class="right">Kg</th>
                                <th class="right">MT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($block['lines'] as $line)
                                <tr>
                                    <td>{{ $line['structure_location'] }}</td>
                                    <td>{{ $line['bar_mark'] }}</td>
                                    <td class="right">{{ $line['diameter_mm'] }}</td>
                                    <td class="right">{{ $line['number_of_members'] }}</td>
                                    <td class="right">{{ $line['bars_per_member'] }}</td>
                                    <td class="right">{{ $line['total_bars'] }}</td>
                                    <td class="right">{{ $line['a_length'] }}</td>
                                    <td class="right">{{ $line['b_length'] }}</td>
                                    <td class="right">{{ $line['c_length'] }}</td>
                                    <td class="right">{{ $line['d_length'] }}</td>
                                    <td class="right">{{ $line['hook_length'] }}</td>
                                    <td class="right">{{ $line['bend_deduction'] }}</td>
                                    <td class="right">{{ $line['cutting_length'] }}</td>
                                    <td class="right">{{ $line['total_length_fmt'] }}</td>
                                    <td class="right">{{ $line['unit_weight'] }}</td>
                                    <td class="right">{{ $line['theoretical_weight_fmt'] }}</td>
                                    <td class="right">{{ $line['theoretical_weight_mt_fmt'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                <h3>Dia-wise Summary</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th class="right">Dia</th>
                            <th class="right">Length</th>
                            <th class="right">Kg</th>
                            <th class="right">MT Theo</th>
                            <th class="right">Wastage %</th>
                            <th class="right">Net MT</th>
                            <th>Material</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($block['diameter_summary'] as $dia)
                            <tr>
                                <td class="right">{{ $dia['diameter_mm'] }}</td>
                                <td class="right">{{ $dia['total_length_fmt'] }}</td>
                                <td class="right">{{ $dia['theoretical_weight_fmt'] }}</td>
                                <td class="right">{{ $dia['theoretical_weight_mt'] ?? '' }}</td>
                                <td class="right">{{ $dia['wastage_percent'] }}</td>
                                <td class="right">{{ $dia['required_weight_mt_fmt'] }}</td>
                                <td>{{ $dia['material'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @endif

        @if (($sections['rate_analysis'] ?? false) && ! empty($rate_analyses))
            <div class="section-break"></div>
            <h2>Rate Analysis</h2>
            <p class="muted">Profiles are shown exactly as saved. Different contexts (e.g. Feed Plant RCC vs M25) are not merged.</p>
            @foreach ($rate_analyses as $ra)
                <h3>{{ $ra['name'] }} <span class="muted">— {{ $ra['unit_rate_fmt'] }} / {{ $ra['unit'] }}</span></h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Type</th>
                            <th class="right">Qty</th>
                            <th class="right">Rate</th>
                            <th class="right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ra['items'] as $line)
                            <tr>
                                <td>{{ $line['description'] }}</td>
                                <td>{{ $line['resource_type'] }}</td>
                                <td class="right">{{ $line['quantity_fmt'] }}</td>
                                <td class="right">{{ $line['rate_fmt'] }}</td>
                                <td class="right">{{ $line['amount_fmt'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @endif

        @if (($sections['rate_comparison'] ?? false) && ! empty($rate_comparison))
            <h2>Rate Comparison</h2>
            <p class="muted">Lowest quote is marked for reference only — not awarded.</p>
            <table class="lines">
                <thead>
                    <tr>
                        <th>BOQ Item</th>
                        <th class="right">Internal</th>
                        <th>Vendor / Contractor</th>
                        <th class="right">Quoted</th>
                        <th>GST</th>
                        <th class="right">Effective</th>
                        <th class="right">Variance</th>
                        <th class="right">Var %</th>
                        <th>Lowest</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rate_comparison as $row)
                        <tr>
                            <td>{{ $row['item'] }}</td>
                            <td class="right">{{ $row['internal_rate_fmt'] }}</td>
                            <td>{{ $row['vendor'] }}</td>
                            <td class="right">{{ $row['quoted_rate_fmt'] }}</td>
                            <td>{{ $row['gst_mode'] }}</td>
                            <td class="right">{{ $row['effective_rate_fmt'] }}</td>
                            <td class="right">{{ $row['variance_fmt'] }}</td>
                            <td class="right">{{ $row['variance_pct'] }}</td>
                            <td>{{ $row['is_lowest'] ? 'Lowest' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['planning_mapping'] ?? false) && ! empty($planning_mapping))
            <h2>Planning / BOQ Mapping</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Planning Activity</th>
                        <th>Unit</th>
                        <th class="right">Plan Qty</th>
                        <th>BOQ Item</th>
                        <th>BOQ Unit</th>
                        <th class="right">Allocated</th>
                        <th>Conversion</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($planning_mapping as $row)
                        <tr>
                            <td>{{ $row['planning_activity'] }}</td>
                            <td>{{ $row['planning_unit'] }}</td>
                            <td class="right">{{ $row['planning_qty'] }}</td>
                            <td>{{ $row['boq_item'] }}</td>
                            <td>{{ $row['boq_unit'] }}</td>
                            <td class="right">{{ $row['allocated_qty'] }}</td>
                            <td>{{ $row['conversion'] }}</td>
                            <td>{{ $row['method'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['budget_status'] ?? false) && ! empty($budget_status))
            <h2>Budget Sync Status</h2>
            <table class="meta">
                <tr><td class="muted">Status</td><td>{{ $budget_status['label'] ?? $budget_status['status'] }}</td></tr>
                <tr><td class="muted">Budget Version</td><td>{{ $budget_status['budget_version'] ?? '—' }}</td></tr>
                <tr><td class="muted">Last Synced</td><td>{{ $budget_status['last_synced_at'] ?? '—' }}</td></tr>
                <tr><td class="muted">Last Synced By</td><td>{{ $budget_status['last_synced_by']['name'] ?? '—' }}</td></tr>
                <tr><td class="muted">Advanced Internal Cost</td><td>{{ $budget_status['source_internal_cost_fmt'] ?? '—' }}</td></tr>
                <tr><td class="muted">Variance</td><td>{{ $budget_status['variance_fmt'] ?? '—' }}</td></tr>
            </table>
            <p class="muted">PDF generation does not sync budget.</p>
        @endif

        @if (($sections['estimate_vs_actual'] ?? false) && ! empty($estimate_vs_actual) && is_array($estimate_vs_actual))
            <div class="section-break"></div>
            <h2>Estimate vs Actual</h2>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th class="right">Est. Qty</th>
                        <th class="right">Budget Qty</th>
                        <th class="right">PO Qty</th>
                        <th class="right">GRN Qty</th>
                        <th class="right">Consumed</th>
                        <th class="right">Variance</th>
                        <th class="right">Est. Cost</th>
                        <th class="right">Actual Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($estimate_vs_actual as $row)
                        @if (is_array($row))
                            <tr>
                                <td>{{ $row['material_name'] ?? $row['material'] ?? '—' }}</td>
                                <td class="right">{{ $row['estimate_qty'] ?? $row['estimated_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['budget_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['po_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['grn_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['consumed_qty'] ?? $row['actual_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['qty_variance'] ?? $row['variance_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['estimate_cost'] ?? $row['estimated_cost'] ?? '—' }}</td>
                                <td class="right">{{ $row['actual_cost'] ?? '—' }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (($sections['revision_comparison'] ?? false) && ! empty($revision_comparison))
            <h2>Revision Comparison Appendix</h2>
            @php
                $rcSummary = $revision_comparison['summary'] ?? [];
                $rcHeader = $revision_comparison['header'] ?? [];
            @endphp
            <p class="muted">
                {{ $rcHeader['previous']['label'] ?? 'Previous' }} → {{ $rcHeader['current']['label'] ?? 'Current' }}
                @if (! empty($rcHeader['current_is_draft']))
                    · Draft Current Values
                @endif
            </p>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th class="right">Previous</th>
                        <th class="right">Current</th>
                        <th class="right">Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Commercial Value</td>
                        <td class="right">{{ $rcSummary['previous_commercial'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['current_commercial'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['commercial_variance'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td>Internal Cost</td>
                        <td class="right">{{ $rcSummary['previous_internal'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['current_internal'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['internal_variance'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td>Margin</td>
                        <td class="right">{{ $rcSummary['previous_margin'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['current_margin'] ?? '—' }}</td>
                        <td class="right">{{ $rcSummary['margin_variance'] ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            @if (! empty($revision_comparison['material_summary']))
                <h3>Material Difference</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th class="right">Prev Qty</th>
                            <th class="right">Curr Qty</th>
                            <th class="right">Amount Δ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($revision_comparison['material_summary'] as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td class="right">{{ $row['previous_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['current_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['amount_difference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if (! empty($revision_comparison['labour_summary']))
                <h3>Labour Difference</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th class="right">Prev Qty</th>
                            <th class="right">Curr Qty</th>
                            <th class="right">Amount Δ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($revision_comparison['labour_summary'] as $row)
                            <tr>
                                <td>{{ $row['name'] ?? '—' }}</td>
                                <td class="right">{{ $row['previous_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['current_qty'] ?? '—' }}</td>
                                <td class="right">{{ $row['amount_difference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if (! empty($revision_comparison['bbs_dia_summary']))
                <h3>BBS Difference</h3>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Dia</th>
                            <th class="right">Prev MT</th>
                            <th class="right">Curr MT</th>
                            <th class="right">Δ MT</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($revision_comparison['bbs_dia_summary'] as $row)
                            <tr>
                                <td>{{ $row['diameter_mm'] ?? '—' }}</td>
                                <td class="right">{{ $row['previous_mt'] ?? '—' }}</td>
                                <td class="right">{{ $row['current_mt'] ?? '—' }}</td>
                                <td class="right">{{ $row['difference_mt'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        <div style="margin-top: 40px;">
            <table>
                <tr>
                    <td style="width: 33%;" class="center">
                        <div class="sig">Prepared By<br>{{ $signatures['prepared_by'] ?? '—' }}</div>
                    </td>
                    <td style="width: 33%;" class="center">
                        <div class="sig">Checked By<br>{{ $signatures['checked_by'] ?? '—' }}</div>
                    </td>
                    <td style="width: 33%;" class="center">
                        <div class="sig">Approved By<br>{{ $signatures['approved_by'] ?? ($signatures['signatory_name'] ?? '—') }}
                            @if (! empty($signatures['signatory_designation']))
                                <div class="muted">{{ $signatures['signatory_designation'] }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
