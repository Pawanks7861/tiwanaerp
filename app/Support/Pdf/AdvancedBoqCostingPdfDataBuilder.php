<?php

namespace App\Support\Pdf;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\CostComponentType;
use App\Enums\Boq\MeasurementFormulaType;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqBbs;
use App\Models\Boq\BoqCostComponent;
use App\Models\Boq\BoqMeasurementLine;
use App\Models\Boq\BoqRateComparisonQuote;
use App\Models\Boq\RateAnalysis;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Services\Boq\AdvancedBoqRevisionComparisonService;
use App\Services\Boq\BbsService;
use App\Services\Boq\BoqBudgetMaterialSyncService;
use App\Services\Boq\CostBuildUpService;
use App\Services\Boq\ProjectMaterialControlService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Format\IndianNumber;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Presentation DTO for Advanced BOQ Internal Costing PDF.
 * Uses saved ERP costing/BBS/quotes — never re-reads the import workbook.
 */
final class AdvancedBoqCostingPdfDataBuilder
{
    public const SECTION_KEYS = [
        'cost_summary',
        'measurements',
        'material_consumption',
        'material_summary',
        'labour_summary',
        'bbs',
        'rate_analysis',
        'rate_comparison',
        'planning_mapping',
        'budget_status',
        'estimate_vs_actual',
        'revision_comparison',
    ];

    public function __construct(
        private readonly BoqBudgetMaterialSyncService $budgetSync,
        private readonly CostBuildUpService $costBuildUp,
        private readonly BbsService $bbs,
        private readonly ProjectMaterialControlService $materialControl,
        private readonly AdvancedBoqRevisionComparisonService $revisionCompare,
    ) {}

    /**
     * @param  array{mode?: string, sections?: array<string, bool>}  $options
     * @return array<string, mixed>
     */
    public function build(Boq $boq, Project $project, Company $company, array $options = []): array
    {
        if (! BoqCompanySettings::enabled($company)) {
            throw ValidationException::withMessages(['boq' => 'Advanced BOQ costing is not enabled for this company.']);
        }

        $mode = ($options['mode'] ?? 'detailed') === 'summary' ? 'summary' : 'detailed';
        $sections = $this->resolveSections($mode, $options['sections'] ?? []);

        $boq->loadMissing([
            'creator:id,name',
            'approver:id,name',
            'technicalApprover:id,name',
            'parent:id,boq_number,version,revision_reason,total_cost_amount,total_client_amount',
            'drawingRevisions.drawing:id,drawing_number,title',
            'sections' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')
                ->with([
                    'unit:id,symbol',
                    'section:id,name,parent_id,code',
                    'costComponents' => fn ($c) => $c->where('is_active', true)->orderBy('sequence')->orderBy('id'),
                    'measurementSheets.lines.unit:id,symbol',
                    'bbs.lines',
                    'bbs.steelMaps.material:id,code,name',
                    'rateComparisonQuotes' => fn ($q) => $q->where('is_active', true)->orderBy('sequence')->orderBy('id'),
                    'planningMaps.task:id,name,wbs_code,planned_qty,unit_id',
                    'planningMaps.task.unit:id,symbol',
                ]),
        ]);

        $settings = DocumentPdfSettings::for($company);
        $proposed = $this->budgetSync->proposedSyncPayload($boq);
        $recon = $proposed['reconciliation'];
        $commercial = Decimal::of((string) ($boq->total_client_amount ?? '0'))->toMoney();
        $internal = Decimal::of((string) $recon['grand'])->toMoney();
        $margin = Decimal::of($commercial)->minus($internal)->toMoney();
        $marginPct = Decimal::of($commercial)->isZero()
            ? null
            : Decimal::of($margin)->dividedBy($commercial, Decimal::PERCENT_SCALE)->times(100)->round(Decimal::PERCENT_SCALE)->toString();

        $itemRows = $this->itemRows($boq);
        $sectionTotals = $this->sectionTotals($itemRows);

        $payload = [
            'boq' => $boq,
            'project' => $project,
            'company' => $company,
            'settings' => $settings,
            'mode' => $mode,
            'sections' => $sections,
            'is_draft' => $boq->status !== BoqStatus::Approved,
            'is_review' => $boq->status === BoqStatus::Submitted,
            'confidential' => true,
            'title' => 'DETAILED COST ESTIMATE',
            'subtitle' => 'Advanced BOQ Internal Costing Report',
            'meta' => [
                'Project' => $project->name,
                'BOQ No.' => $boq->boq_number,
                'Title' => $boq->title,
                'Revision' => 'R'.$boq->version,
                'Status' => $boq->status->label(),
                'Approval Stage' => method_exists($boq, 'approvalStage') ? $boq->approvalStage()->label() : $boq->status->label(),
                'Technical Approved' => $boq->technical_approved_at
                    ? ($boq->technicalApprover?->name ?? 'Yes').' · '.$boq->technical_approved_at->format('d-M-Y')
                    : 'Pending',
                'Commercial / Final' => $boq->approved_at
                    ? ($boq->approver?->name ?? 'Yes').' · '.$boq->approved_at->format('d-M-Y')
                    : ($boq->technical_approved_at ? 'Pending Commercial' : '—'),
                'Date' => $boq->created_at?->format('d-M-Y'),
                'Prepared By' => $boq->creator?->name,
                'Approved By' => $boq->approver?->name,
                'Drawings' => $boq->drawingRevisions
                    ->map(fn ($r) => trim(($r->drawing?->drawing_number ?? '').' '.$r->revision_code))
                    ->filter()
                    ->implode(', ') ?: null,
                'Previous Revision' => $boq->parent
                    ? 'R'.$boq->parent->version.($boq->revision_reason ? ' — '.$boq->revision_reason : '')
                    : null,
            ],
            'executive' => [
                'material_a' => $recon['material'],
                'labour_b' => $recon['labour'],
                'equipment_c' => $recon['equipment'],
                'other_d' => $recon['other'],
                'grand_internal' => $internal,
                'commercial' => $commercial,
                'margin' => $margin,
                'margin_pct' => $marginPct,
                'material_a_fmt' => IndianNumber::money($recon['material']),
                'labour_b_fmt' => IndianNumber::money($recon['labour']),
                'equipment_c_fmt' => IndianNumber::money($recon['equipment']),
                'other_d_fmt' => IndianNumber::money($recon['other']),
                'grand_internal_fmt' => IndianNumber::money($internal),
                'commercial_fmt' => IndianNumber::money($commercial),
                'margin_fmt' => IndianNumber::money($margin),
                'margin_pct_fmt' => $marginPct !== null ? IndianNumber::rate($marginPct).'%' : '—',
            ],
            'item_rows' => $itemRows,
            'section_totals' => $sectionTotals,
            'signatures' => [
                'prepared_by' => $boq->creator?->name,
                'checked_by' => null,
                'approved_by' => $boq->approver?->name,
                'signatory_name' => $settings['signatory_name'],
                'signatory_designation' => $settings['signatory_designation'],
            ],
            'footer_note' => $settings['computer_generated']
                ? 'This is a Computer Generated Internal Costing Document'
                : 'INTERNAL / CONFIDENTIAL — Advanced BOQ Costing Report',
        ];

        if ($sections['measurements']) {
            $payload['measurements'] = $this->measurements($boq);
        }
        if ($sections['material_consumption'] || $sections['material_summary'] || $sections['labour_summary']) {
            $matsLab = $this->materialsAndLabour($boq);
            if ($sections['material_consumption']) {
                $payload['material_consumption'] = $matsLab['consumption'];
            }
            if ($sections['material_summary']) {
                $payload['material_summary'] = $matsLab['summary'];
            }
            if ($sections['labour_summary']) {
                $payload['labour_summary'] = $matsLab['labour'];
            }
        }
        if ($sections['bbs']) {
            $payload['bbs'] = $this->bbsBlocks($boq);
        }
        if ($sections['rate_analysis']) {
            $payload['rate_analyses'] = $this->rateAnalyses($project);
        }
        if ($sections['rate_comparison']) {
            $payload['rate_comparison'] = $this->rateComparison($boq);
        }
        if ($sections['planning_mapping']) {
            $payload['planning_mapping'] = $this->planningMapping($boq);
        }
        if ($sections['budget_status']) {
            $status = $this->budgetSync->syncStatus($project, $boq);
            $payload['budget_status'] = [
                ...$status,
                'generated_budget_cost' => $status['source_internal_cost'] !== null
                    ? Decimal::of((string) ($status['variance'] ?? '0'))->plus((string) $status['source_internal_cost'])->toMoney()
                    : null,
                'source_internal_cost_fmt' => isset($status['source_internal_cost']) && $status['source_internal_cost'] !== null
                    ? IndianNumber::money((string) $status['source_internal_cost'])
                    : '—',
                'variance_fmt' => isset($status['variance']) && $status['variance'] !== null
                    ? IndianNumber::money((string) $status['variance'])
                    : '—',
            ];
        }
        if ($sections['estimate_vs_actual']) {
            $report = $this->materialControl->report($project, ['boq_id' => $boq->id]);
            $payload['estimate_vs_actual'] = $report['rows'] ?? $report['materials'] ?? $report;
        }
        if ($sections['revision_comparison']) {
            $fromId = (int) ($options['from_boq_id'] ?? $boq->parent_boq_id ?? 0);
            $toId = (int) ($options['to_boq_id'] ?? $boq->id);
            if ($fromId > 0 && $toId > 0 && $fromId !== $toId) {
                $from = $fromId === (int) $boq->id
                    ? $boq
                    : ($boq->parent_boq_id === $fromId ? $boq->parent : Boq::query()->find($fromId));
                $to = $toId === (int) $boq->id ? $boq : Boq::query()->find($toId);
                if ($from instanceof Boq && $to instanceof Boq) {
                    $payload['revision_comparison'] = $this->revisionCompare->compare($from, $to, true);
                }
            } elseif ($boq->parent_boq_id) {
                $payload['revision_comparison'] = $this->revisionCompare->compare($boq->parent, $boq, true);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, bool>  $requested
     * @return array<string, bool>
     */
    private function resolveSections(string $mode, array $requested): array
    {
        $defaults = [
            'cost_summary' => true,
            'measurements' => $mode === 'detailed',
            'material_consumption' => $mode === 'detailed',
            'material_summary' => true,
            'labour_summary' => true,
            'bbs' => true,
            'rate_analysis' => $mode === 'detailed',
            'rate_comparison' => false,
            'planning_mapping' => false,
            'budget_status' => false,
            'estimate_vs_actual' => false,
            'revision_comparison' => false,
        ];

        if ($mode === 'summary') {
            $defaults['measurements'] = false;
            $defaults['material_consumption'] = false;
            $defaults['rate_analysis'] = false;
            $defaults['bbs'] = true; // summary table only in blade
        }

        foreach (self::SECTION_KEYS as $key) {
            if (array_key_exists($key, $requested)) {
                $defaults[$key] = (bool) $requested[$key];
            }
        }

        $defaults['cost_summary'] = true;

        return $defaults;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemRows(Boq $boq): array
    {
        $rows = [];
        $sr = 0;
        foreach ($boq->items as $item) {
            $sr++;
            $qty = Decimal::of((string) $item->quantity);
            $components = $item->costComponents;
            $cats = $this->costBuildUp->categoryTotals($components);
            $material = Decimal::of($cats['material'])->times($qty)->toMoney();
            $labour = Decimal::of($cats['labour'])->times($qty)->toMoney();
            $equipment = Decimal::of($cats['equipment'])->times($qty)->toMoney();
            $other = Decimal::of($cats['subcontract'])->plus($cats['other'])->times($qty)->toMoney();
            $internal = Decimal::of($material)->plus($labour)->plus($equipment)->plus($other)->toMoney();
            $internalRate = $qty->isZero() ? '0.0000' : Decimal::of($internal)->dividedBy($qty, Decimal::RATE_SCALE)->toRate();
            $commercial = Decimal::of((string) ($item->client_amount ?? '0'))->toMoney();
            if (Decimal::of($commercial)->isZero() && ! Decimal::of((string) ($item->client_rate ?? '0'))->isZero()) {
                $commercial = $qty->times((string) $item->client_rate)->toMoney();
            }
            $margin = Decimal::of($commercial)->minus($internal)->toMoney();
            $marginPct = Decimal::of($commercial)->isZero()
                ? null
                : Decimal::of($margin)->dividedBy($commercial, Decimal::PERCENT_SCALE)->times(100)->round(Decimal::PERCENT_SCALE)->toString();

            $section = $item->section;
            $sectionLabel = $section?->name ?? 'General';
            if ($section?->parent_id) {
                $parent = $boq->sections->firstWhere('id', $section->parent_id);
                $sectionLabel = ($parent?->name ?? '').' / '.$section->name;
            }

            $rows[] = [
                'sr' => $sr,
                'section' => $sectionLabel,
                'section_id' => $section?->id,
                'item_id' => $item->id,
                'description' => $item->name,
                'unit' => $item->unit?->symbol,
                'qty' => Decimal::of((string) $item->quantity)->toQuantity(),
                'qty_fmt' => IndianNumber::quantity((string) $item->quantity),
                'material' => $material,
                'labour' => $labour,
                'equipment' => $equipment,
                'other' => $other,
                'internal' => $internal,
                'internal_rate' => $internalRate,
                'client_rate' => Decimal::of((string) ($item->client_rate ?? '0'))->toRate(),
                'commercial' => $commercial,
                'margin' => $margin,
                'margin_pct' => $marginPct,
                'material_fmt' => IndianNumber::money($material),
                'labour_fmt' => IndianNumber::money($labour),
                'equipment_fmt' => IndianNumber::money($equipment),
                'other_fmt' => IndianNumber::money($other),
                'internal_fmt' => IndianNumber::money($internal),
                'internal_rate_fmt' => IndianNumber::rate($internalRate),
                'client_rate_fmt' => IndianNumber::rate((string) ($item->client_rate ?? '0')),
                'commercial_fmt' => IndianNumber::money($commercial),
                'margin_fmt' => IndianNumber::money($margin),
                'margin_pct_fmt' => $marginPct !== null ? IndianNumber::rate($marginPct).'%' : '—',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $itemRows
     * @return list<array<string, mixed>>
     */
    private function sectionTotals(array $itemRows): array
    {
        $groups = [];
        foreach ($itemRows as $row) {
            $key = (string) ($row['section'] ?? 'General');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'section' => $key,
                    'material' => Decimal::zero(),
                    'labour' => Decimal::zero(),
                    'other' => Decimal::zero(),
                    'internal' => Decimal::zero(),
                    'commercial' => Decimal::zero(),
                ];
            }
            $groups[$key]['material'] = $groups[$key]['material']->plus($row['material']);
            $groups[$key]['labour'] = $groups[$key]['labour']->plus($row['labour']);
            $groups[$key]['other'] = $groups[$key]['other']->plus($row['equipment'])->plus($row['other']);
            $groups[$key]['internal'] = $groups[$key]['internal']->plus($row['internal']);
            $groups[$key]['commercial'] = $groups[$key]['commercial']->plus($row['commercial']);
        }

        return collect($groups)->map(function (array $g) {
            $margin = $g['commercial']->minus($g['internal'])->toMoney();

            return [
                'section' => $g['section'],
                'material' => $g['material']->toMoney(),
                'labour' => $g['labour']->toMoney(),
                'other' => $g['other']->toMoney(),
                'internal' => $g['internal']->toMoney(),
                'commercial' => $g['commercial']->toMoney(),
                'margin' => $margin,
                'material_fmt' => IndianNumber::money($g['material']->toMoney()),
                'labour_fmt' => IndianNumber::money($g['labour']->toMoney()),
                'other_fmt' => IndianNumber::money($g['other']->toMoney()),
                'internal_fmt' => IndianNumber::money($g['internal']->toMoney()),
                'commercial_fmt' => IndianNumber::money($g['commercial']->toMoney()),
                'margin_fmt' => IndianNumber::money($margin),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function measurements(Boq $boq): array
    {
        $blocks = [];
        foreach ($boq->items as $item) {
            $lines = [];
            foreach ($item->measurementSheets as $sheet) {
                foreach ($sheet->lines as $line) {
                    /** @var BoqMeasurementLine $line */
                    $lines[] = [
                        'description' => $line->description ?: $item->name,
                        'unit' => $line->unit?->symbol ?? $item->unit?->symbol,
                        'nos' => $line->nos !== null ? (string) $line->nos : null,
                        'length' => $line->length !== null ? (string) $line->length : null,
                        'width' => $line->width !== null ? (string) $line->width : null,
                        'height_depth' => $line->height_depth !== null ? (string) $line->height_depth : null,
                        'formula' => $this->humanFormula($line),
                        'calculated_qty' => (string) $line->calculated_quantity,
                        'final_qty' => (string) $line->calculated_quantity,
                        'remarks' => $line->remarks,
                        'calculated_qty_fmt' => IndianNumber::quantity((string) $line->calculated_quantity),
                    ];
                }
            }
            if ($lines === []) {
                continue;
            }
            $blocks[] = [
                'item' => $item->name,
                'unit' => $item->unit?->symbol,
                'lines' => $lines,
                'total' => Decimal::of((string) $item->quantity)->toQuantity(),
                'total_fmt' => IndianNumber::quantity((string) $item->quantity),
            ];
        }

        return $blocks;
    }

    private function humanFormula(BoqMeasurementLine $line): string
    {
        $type = $line->formula_type instanceof MeasurementFormulaType
            ? $line->formula_type
            : MeasurementFormulaType::tryFrom((string) $line->formula_type);

        $parts = array_values(array_filter([
            $line->nos !== null ? rtrim(rtrim((string) $line->nos, '0'), '.') : null,
            $line->length !== null ? rtrim(rtrim((string) $line->length, '0'), '.') : null,
            $line->width !== null ? rtrim(rtrim((string) $line->width, '0'), '.') : null,
            $line->height_depth !== null ? rtrim(rtrim((string) $line->height_depth, '0'), '.') : null,
        ], fn ($v) => $v !== null && $v !== ''));

        if ($type === MeasurementFormulaType::Manual) {
            return 'Manual '.$line->calculated_quantity;
        }
        if ($type === MeasurementFormulaType::CircleArea || $type === MeasurementFormulaType::CircularVolume) {
            return $type->label();
        }

        return $parts !== [] ? implode(' × ', $parts) : ($type?->label() ?? '—');
    }

    /**
     * @return array{consumption: list<array<string, mixed>>, summary: list<array<string, mixed>>, labour: list<array<string, mixed>>}
     */
    private function materialsAndLabour(Boq $boq): array
    {
        $consumption = [];
        $labour = [];
        $agg = [];

        foreach ($boq->items as $item) {
            $qty = Decimal::of((string) $item->quantity);
            foreach ($item->costComponents as $c) {
                /** @var BoqCostComponent $c */
                if ($c->component_type === CostComponentType::Material) {
                    $gross = $qty->times((string) $c->consumption_qty);
                    $wastage = Decimal::of((string) ($c->wastage_percent ?? '0'));
                    $net = $gross->times(Decimal::of('1')->plus($wastage->dividedBy(100, 8)));
                    $amount = Decimal::of((string) $c->amount_per_boq_unit)->times($qty)->toMoney();
                    $row = [
                        'item' => $item->name,
                        'material' => $c->description,
                        'boq_qty' => $qty->toQuantity(),
                        'consumption' => Decimal::of((string) $c->consumption_qty)->toQuantity(),
                        'gross' => $gross->toQuantity(),
                        'wastage_percent' => $wastage->round(Decimal::PERCENT_SCALE)->toString(),
                        'net' => $net->toQuantity(),
                        'unit' => $c->consumption_unit,
                        'rate' => Decimal::of((string) $c->rate)->toRate(),
                        'amount' => $amount,
                        'profile' => null,
                        'boq_qty_fmt' => IndianNumber::quantity($qty->toQuantity()),
                        'consumption_fmt' => IndianNumber::quantity((string) $c->consumption_qty),
                        'gross_fmt' => IndianNumber::quantity($gross->toQuantity()),
                        'net_fmt' => IndianNumber::quantity($net->toQuantity()),
                        'rate_fmt' => IndianNumber::rate((string) $c->rate),
                        'amount_fmt' => IndianNumber::money($amount),
                    ];
                    $consumption[] = $row;
                    $key = mb_strtolower(trim($c->description)).'|'.mb_strtolower((string) $c->consumption_unit);
                    if (! isset($agg[$key])) {
                        $agg[$key] = [
                            'material' => $c->description,
                            'unit' => $c->consumption_unit,
                            'gross' => Decimal::zero(),
                            'wastage' => Decimal::zero(),
                            'net' => Decimal::zero(),
                            'amount' => Decimal::zero(),
                        ];
                    }
                    $agg[$key]['gross'] = $agg[$key]['gross']->plus($gross);
                    $agg[$key]['net'] = $agg[$key]['net']->plus($net);
                    $agg[$key]['wastage'] = $agg[$key]['wastage']->plus($net->minus($gross));
                    $agg[$key]['amount'] = $agg[$key]['amount']->plus($amount);
                }
                if ($c->component_type === CostComponentType::Labour) {
                    $amount = Decimal::of((string) $c->amount_per_boq_unit)->times($qty)->toMoney();
                    $labour[] = [
                        'activity' => $c->description,
                        'item' => $item->name,
                        'unit' => $c->consumption_unit ?: $item->unit?->symbol,
                        'quantity' => Decimal::of((string) $c->consumption_qty)->times($qty)->toQuantity(),
                        'rate' => Decimal::of((string) $c->rate)->toRate(),
                        'amount' => $amount,
                        'quantity_fmt' => IndianNumber::quantity(Decimal::of((string) $c->consumption_qty)->times($qty)->toQuantity()),
                        'rate_fmt' => IndianNumber::rate((string) $c->rate),
                        'amount_fmt' => IndianNumber::money($amount),
                        'is_steel_fixing' => str_contains(mb_strtolower($c->description), 'steel')
                            || str_contains(mb_strtolower($c->description), 'fixing'),
                    ];
                }
            }
        }

        $summary = collect($agg)->map(function (array $g) {
            $net = $g['net']->toQuantity();
            $amount = $g['amount']->toMoney();
            $weighted = Decimal::of($net)->isZero()
                ? '0.0000'
                : Decimal::of($amount)->dividedBy($net, Decimal::RATE_SCALE)->toRate();

            return [
                'material' => $g['material'],
                'unit' => $g['unit'],
                'gross' => $g['gross']->toQuantity(),
                'wastage' => $g['wastage']->toQuantity(),
                'net' => $net,
                'weighted_rate' => $weighted,
                'amount' => $amount,
                'gross_fmt' => IndianNumber::quantity($g['gross']->toQuantity()),
                'wastage_fmt' => IndianNumber::quantity($g['wastage']->toQuantity()),
                'net_fmt' => IndianNumber::quantity($net),
                'weighted_rate_fmt' => IndianNumber::rate($weighted),
                'amount_fmt' => IndianNumber::money($amount),
            ];
        })->values()->all();

        return compact('consumption', 'summary', 'labour');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bbsBlocks(Boq $boq): array
    {
        $blocks = [];
        foreach ($boq->items as $item) {
            if (! $item->bbs) {
                continue;
            }
            /** @var BoqBbs $bbs */
            $bbs = $item->bbs;
            $payload = $this->bbs->bbsPayload($bbs->loadMissing(['lines', 'steelMaps.material', 'drawingRevision.drawing']));
            $lines = collect($payload['lines'] ?? [])->map(function (array $line) {
                return [
                    ...$line,
                    'total_length_fmt' => IndianNumber::quantity((string) ($line['total_length'] ?? '0')),
                    'theoretical_weight_fmt' => IndianNumber::quantity((string) ($line['theoretical_weight'] ?? '0')),
                    'theoretical_weight_mt' => Decimal::of((string) ($line['theoretical_weight'] ?? '0'))
                        ->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity(),
                    'theoretical_weight_mt_fmt' => IndianNumber::quantity(
                        Decimal::of((string) ($line['theoretical_weight'] ?? '0'))->dividedBy(1000, Decimal::QTY_SCALE)->toQuantity()
                    ),
                ];
            })->all();

            $dia = collect($payload['diameter_summary'] ?? [])->map(function (array $row) {
                return [
                    ...$row,
                    'total_length_fmt' => IndianNumber::quantity((string) ($row['total_length'] ?? '0')),
                    'theoretical_weight_fmt' => IndianNumber::quantity((string) ($row['theoretical_weight'] ?? '0')),
                    'required_weight_fmt' => IndianNumber::quantity((string) ($row['required_weight'] ?? '0')),
                    'required_weight_mt_fmt' => IndianNumber::quantity((string) ($row['required_weight_mt'] ?? '0')),
                    'wastage_percent' => (string) ($payload['wastage_percent'] ?? '0'),
                ];
            })->all();

            $blocks[] = [
                'item' => $item->name,
                'title' => $payload['title'] ?? $bbs->title,
                'wastage_percent' => (string) ($payload['wastage_percent'] ?? '0'),
                'required_weight' => (string) ($payload['required_weight'] ?? '0'),
                'required_weight_mt' => (string) ($payload['required_weight_mt'] ?? '0'),
                'required_weight_fmt' => IndianNumber::quantity((string) ($payload['required_weight'] ?? '0')),
                'required_weight_mt_fmt' => IndianNumber::quantity((string) ($payload['required_weight_mt'] ?? '0')),
                'lines' => $lines,
                'diameter_summary' => $dia,
            ];
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rateAnalyses(Project $project): array
    {
        return RateAnalysis::query()
            ->where('project_id', $project->id)
            ->with(['items', 'unit:id,symbol'])
            ->orderBy('name')
            ->get()
            ->map(function (RateAnalysis $ra) {
                return [
                    'name' => $ra->name,
                    'unit' => $ra->unit?->symbol,
                    'unit_rate' => (string) $ra->unit_rate,
                    'unit_rate_fmt' => IndianNumber::rate((string) $ra->unit_rate),
                    'items' => $ra->items->map(fn ($i) => [
                        'description' => $i->description,
                        'resource_type' => $i->resource_type?->value ?? $i->resource_type,
                        'quantity' => (string) $i->quantity,
                        'rate' => (string) $i->rate,
                        'amount' => (string) $i->amount,
                        'quantity_fmt' => IndianNumber::quantity((string) $i->quantity),
                        'rate_fmt' => IndianNumber::rate((string) $i->rate),
                        'amount_fmt' => IndianNumber::money((string) $i->amount),
                    ])->all(),
                ];
            })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rateComparison(Boq $boq): array
    {
        $rows = [];
        foreach ($boq->items as $item) {
            foreach ($item->rateComparisonQuotes as $quote) {
                /** @var BoqRateComparisonQuote $quote */
                $rows[] = [
                    'item' => $item->name,
                    'internal_rate' => (string) $quote->internal_cost_rate_snapshot,
                    'vendor' => $quote->vendor_name,
                    'quoted_rate' => (string) $quote->quoted_rate,
                    'gst_mode' => $quote->gst_mode?->value ?? (string) $quote->gst_mode,
                    'effective_rate' => (string) ($quote->effective_rate ?? $quote->quoted_rate),
                    'variance' => (string) ($quote->variance_vs_internal ?? '0'),
                    'variance_pct' => $quote->variance_vs_internal_percent !== null
                        ? (string) $quote->variance_vs_internal_percent
                        : null,
                    'is_lowest' => (bool) ($quote->is_lowest ?? false),
                    'internal_rate_fmt' => IndianNumber::rate((string) $quote->internal_cost_rate_snapshot),
                    'quoted_rate_fmt' => IndianNumber::rate((string) $quote->quoted_rate),
                    'effective_rate_fmt' => IndianNumber::rate((string) ($quote->effective_rate ?? $quote->quoted_rate)),
                    'variance_fmt' => IndianNumber::money((string) ($quote->variance_vs_internal ?? '0')),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planningMapping(Boq $boq): array
    {
        $rows = [];
        foreach ($boq->items as $item) {
            foreach ($item->planningMaps as $map) {
                $task = $map->task;
                $rows[] = [
                    'planning_head' => $task?->parent_id ? null : $task?->name,
                    'planning_activity' => $task?->name,
                    'planning_unit' => $task?->unit?->symbol,
                    'planning_qty' => $map->planning_qty !== null
                        ? (string) $map->planning_qty
                        : ($task?->planned_qty !== null ? (string) $task->planned_qty : null),
                    'boq_item' => $item->name,
                    'boq_unit' => $item->unit?->symbol,
                    'allocated_qty' => (string) ($map->allocated_boq_qty ?? '0'),
                    'conversion' => (string) ($map->conversion_factor ?? '—'),
                    'method' => $map->mapping_method?->label() ?? $map->mapping_method?->value ?? '—',
                ];
            }
        }

        return $rows;
    }
}
