<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Boq\VariationChangeType;
use App\Enums\Boq\VariationSourceType;
use App\Enums\Boq\VariationStatus;
use App\Exports\BoqVariationRegisterExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqVariation;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Services\Boq\EffectiveBoqQuantityService;
use App\Services\Boq\VariationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoqVariationController extends Controller
{
    public function __construct(
        private readonly VariationService $variations,
        private readonly EffectiveBoqQuantityService $effective,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_variations'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $canCosts = $request->user()->can('boq.view_costs');
        $query = BoqVariation::query()
            ->where('project_id', $project->id)
            ->with(['submittedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($q) use ($term) {
                $q->where('variation_number', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            });
        }

        $rows = $query->get()->map(function (BoqVariation $v) use ($canCosts) {
            $row = [
                'id' => $v->id,
                'variation_number' => $v->variation_number,
                'title' => $v->title,
                'change_order_type' => $v->change_order_type->value,
                'change_order_type_label' => $v->change_order_type->label(),
                'source_type' => $v->source_type->value,
                'source_type_label' => $v->source_type->label(),
                'status' => $v->status->value,
                'status_label' => $v->status->label(),
                'submitted_by' => $v->submittedBy?->name,
                'approved_by' => $v->approvedBy?->name,
                'approved_at' => $v->approved_at?->toDateTimeString(),
                'created_at' => $v->created_at?->toDateTimeString(),
            ];
            if ($canCosts) {
                $row['revenue_impact'] = (string) $v->revenue_impact;
                $row['cost_impact'] = (string) $v->cost_impact;
                $row['margin_impact'] = (string) $v->margin_impact;
            }

            return $row;
        });

        $contract = $canCosts ? $this->effective->contractValueSummary($project) : null;

        return Inertia::render('Boq/Variations/Index', [
            'project' => ProjectHeader::for($project),
            'rows' => $rows,
            'filters' => $filters,
            'contract' => $contract,
            'status_options' => collect(VariationStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ])->prepend(['value' => null, 'label' => '— All —'])->values()->all(),
            'can' => [
                'manage' => $request->user()->can('boq.manage_variations'),
                'approve' => $request->user()->can('boq.approve_variations'),
                'view_costs' => $canCosts,
                'export' => true,
            ],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);

        return Inertia::render('Boq/Variations/Form', [
            'project' => ProjectHeader::for($project),
            'variation' => null,
            'options' => $this->formOptions($project),
            'can' => ['view_costs' => $request->user()->can('boq.view_costs')],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);

        $boq = $this->currentApprovedBoq($project);
        $data = $this->validated($request);
        $variation = $this->variations->create($project, $boq, $data['header'], $data['items'], $request->user());

        return redirect()
            ->route('projects.variations.show', [$project, $variation])
            ->with('success', "Variation {$variation->variation_number} saved as draft.");
    }

    public function show(Request $request, Project $project, BoqVariation $variation): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $variation->load([
            'items.unit:id,symbol',
            'items.section:id,code,name',
            'items.originalItem:id,item_code,name,line_uid,quantity',
            'drawingRevision.drawing:id,drawing_number,title',
            'submittedBy:id,name',
            'approvedBy:id,name',
            'rejectedBy:id,name',
            'boq:id,boq_number,version',
        ]);

        $canCosts = $request->user()->can('boq.view_costs');
        $preview = $variation->status === VariationStatus::Submitted
            ? $this->variations->approvalPreview($variation)
            : null;

        if ($preview && ! $canCosts) {
            unset($preview['variation_cost_impact'], $preview['variation_margin_impact']);
        }

        $contract = $canCosts ? $this->effective->contractValueSummary($project) : null;

        return Inertia::render('Boq/Variations/Show', [
            'project' => ProjectHeader::for($project),
            'variation' => $this->payload($variation, $canCosts),
            'preview' => $preview,
            'contract' => $contract,
            'can' => [
                'manage' => $request->user()->can('boq.manage_variations') && $variation->isEditable(),
                'submit' => $request->user()->can('boq.manage_variations') && $variation->isEditable(),
                'approve' => $request->user()->can('boq.approve_variations') && $variation->status === VariationStatus::Submitted,
                'reject' => $request->user()->can('boq.approve_variations') && $variation->status === VariationStatus::Submitted,
                'view_costs' => $canCosts,
            ],
        ]);
    }

    public function edit(Request $request, Project $project, BoqVariation $variation): Response
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);
        abort_unless($variation->isEditable(), 403);

        $variation->load(['items.unit:id,symbol', 'items.section:id,code,name']);

        return Inertia::render('Boq/Variations/Form', [
            'project' => ProjectHeader::for($project),
            'variation' => $this->payload($variation, $request->user()->can('boq.view_costs')),
            'options' => $this->formOptions($project),
            'can' => ['view_costs' => $request->user()->can('boq.view_costs')],
        ]);
    }

    public function update(Request $request, Project $project, BoqVariation $variation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $data = $this->validated($request);
        $this->variations->update($variation, $data['header'], $data['items'], $request->user());

        return redirect()
            ->route('projects.variations.show', [$project, $variation])
            ->with('success', 'Variation updated.');
    }

    public function destroy(Request $request, Project $project, BoqVariation $variation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $number = $variation->variation_number;
        $this->variations->delete($variation);

        return redirect()
            ->route('projects.variations.index', $project)
            ->with('success', "Variation {$number} deleted.");
    }

    public function submit(Request $request, Project $project, BoqVariation $variation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.manage_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $this->variations->submit($variation, $request->user());

        return back()->with('success', 'Variation submitted for approval.');
    }

    public function approve(Request $request, Project $project, BoqVariation $variation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.approve_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $this->variations->approve($variation, $request->user());

        return back()->with('success', 'Variation approved. Effective BOQ scope updated.');
    }

    public function reject(Request $request, Project $project, BoqVariation $variation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.approve_variations'), 403);
        abort_unless((int) $variation->project_id === (int) $project->id, 404);

        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->variations->reject($variation, $request->user(), $data['rejection_reason']);

        return back()->with('success', 'Variation rejected.');
    }

    public function export(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        abort_unless($request->user()->can('boq.view_variations'), 403);

        $rows = BoqVariation::query()
            ->where('project_id', $project->id)
            ->with(['submittedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('id')
            ->get();
        $name = 'variation-register-'.$project->code.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new BoqVariationRegisterExport($project, $rows, $request->user()->can('boq.view_costs')),
            $name,
        );
    }

    /**
     * @return array{header: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'change_order_type' => ['required', 'string'],
            'source_type' => ['required', 'string'],
            'source_reference' => ['nullable', 'string', 'max:120'],
            'drawing_revision_id' => ['nullable', 'integer'],
            'client_instruction_reference' => ['nullable', 'string', 'max:120'],
            'client_instruction_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.change_type' => ['required', 'string'],
            'items.*.original_boq_item_id' => ['nullable', 'integer'],
            'items.*.variation_item_uid' => ['nullable', 'uuid'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.item_code' => ['nullable', 'string', 'max:30'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.boq_section_id' => ['nullable', 'integer'],
            'items.*.quantity_source' => ['nullable', 'string'],
            'items.*.quantity_change' => ['nullable', 'numeric'],
            'items.*.proposed_selling_rate' => ['nullable', 'numeric'],
            'items.*.proposed_cost_rate' => ['nullable', 'numeric'],
            'items.*.gst_rate' => ['nullable', 'numeric'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.measurement_payload' => ['nullable', 'array'],
        ]);

        return [
            'header' => [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'change_order_type' => $data['change_order_type'],
                'source_type' => $data['source_type'],
                'source_reference' => $data['source_reference'] ?? null,
                'drawing_revision_id' => $data['drawing_revision_id'] ?? null,
                'client_instruction_reference' => $data['client_instruction_reference'] ?? null,
                'client_instruction_date' => $data['client_instruction_date'] ?? null,
                'reason' => $data['reason'],
            ],
            'items' => $data['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BoqVariation $variation, bool $canCosts): array
    {
        return [
            'id' => $variation->id,
            'variation_number' => $variation->variation_number,
            'title' => $variation->title,
            'description' => $variation->description,
            'change_order_type' => $variation->change_order_type->value,
            'source_type' => $variation->source_type->value,
            'source_reference' => $variation->source_reference,
            'drawing_revision_id' => $variation->drawing_revision_id,
            'drawing' => $variation->drawingRevision ? [
                'id' => $variation->drawingRevision->id,
                'label' => trim(($variation->drawingRevision->drawing?->drawing_number ?? '').' '.$variation->drawingRevision->revision_code),
            ] : null,
            'client_instruction_reference' => $variation->client_instruction_reference,
            'client_instruction_date' => $variation->client_instruction_date?->toDateString(),
            'reason' => $variation->reason,
            'status' => $variation->status->value,
            'status_label' => $variation->status->label(),
            'revenue_impact' => $canCosts ? (string) $variation->revenue_impact : null,
            'cost_impact' => $canCosts ? (string) $variation->cost_impact : null,
            'margin_impact' => $canCosts ? (string) $variation->margin_impact : null,
            'submitted_by' => $variation->submittedBy?->name,
            'submitted_at' => $variation->submitted_at?->toDateTimeString(),
            'approved_by' => $variation->approvedBy?->name,
            'approved_at' => $variation->approved_at?->toDateTimeString(),
            'rejected_by' => $variation->rejectedBy?->name,
            'rejected_at' => $variation->rejected_at?->toDateTimeString(),
            'rejection_reason' => $variation->rejection_reason,
            'boq' => $variation->boq ? [
                'id' => $variation->boq->id,
                'boq_number' => $variation->boq->boq_number,
                'version' => $variation->boq->version,
            ] : null,
            'items' => $variation->items->map(function ($i) use ($canCosts) {
                $row = [
                    'id' => $i->id,
                    'variation_item_uid' => $i->variation_item_uid,
                    'change_type' => $i->change_type->value,
                    'change_type_label' => $i->change_type->label(),
                    'original_boq_item_id' => $i->original_boq_item_id,
                    'original_line_uid' => $i->original_line_uid,
                    'item_code' => $i->item_code,
                    'name' => $i->name,
                    'description' => $i->description,
                    'hsn_sac' => $i->hsn_sac,
                    'unit_id' => $i->unit_id,
                    'unit' => $i->unit?->symbol,
                    'boq_section_id' => $i->boq_section_id,
                    'quantity_source' => $i->quantity_source->value,
                    'original_quantity' => (string) $i->original_quantity,
                    'quantity_change' => (string) $i->quantity_change,
                    'revised_quantity' => (string) $i->revised_quantity,
                    'original_selling_rate' => (string) $i->original_selling_rate,
                    'proposed_selling_rate' => (string) $i->proposed_selling_rate,
                    'gst_rate' => (string) $i->gst_rate,
                    'notes' => $i->notes,
                    'measurement_payload' => $i->measurement_payload,
                    'materialized_boq_item_id' => $i->materialized_boq_item_id,
                ];
                if ($canCosts) {
                    $row['original_cost_rate'] = (string) $i->original_cost_rate;
                    $row['proposed_cost_rate'] = (string) $i->proposed_cost_rate;
                    $row['revenue_impact'] = (string) $i->revenue_impact;
                    $row['cost_impact'] = (string) $i->cost_impact;
                    $row['margin_impact'] = (string) $i->margin_impact;
                }

                return $row;
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Project $project): array
    {
        $boq = $this->currentApprovedBoq($project);

        return [
            'boq' => ['id' => $boq->id, 'boq_number' => $boq->boq_number, 'version' => $boq->version],
            'change_types' => collect(VariationChangeType::cases())->map(fn ($c) => [
                'value' => $c->value, 'label' => $c->label(),
            ])->all(),
            'source_types' => collect(VariationSourceType::cases())->map(fn ($c) => [
                'value' => $c->value, 'label' => $c->label(),
            ])->all(),
            'boq_items' => BoqItem::query()->where('boq_id', $boq->id)
                ->where(fn ($q) => $q->where('is_variation_originated', false)->orWhereNull('is_variation_originated'))
                ->orderBy('sort_order')->get(['id', 'item_code', 'name', 'line_uid', 'quantity', 'client_rate', 'selling_rate', 'cost_rate', 'unit_id', 'boq_section_id', 'hsn_sac'])
                ->map(fn (BoqItem $i) => [
                    'value' => $i->id,
                    'label' => trim(($i->item_code ? $i->item_code.' — ' : '').$i->name),
                    'line_uid' => $i->line_uid,
                    'quantity' => (string) $i->quantity,
                    'client_rate' => (string) ($i->client_rate ?: $i->selling_rate),
                    'cost_rate' => (string) $i->cost_rate,
                    'unit_id' => $i->unit_id,
                    'boq_section_id' => $i->boq_section_id,
                    'hsn_sac' => $i->hsn_sac,
                ])->all(),
            'sections' => $boq->sections()->orderBy('sort_order')->get(['id', 'code', 'name'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => trim($s->code.' '.$s->name)])->all(),
            'units' => Unit::query()->orderBy('symbol')->limit(500)->get(['id', 'symbol', 'name'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol])->all(),
        ];
    }

    private function currentApprovedBoq(Project $project): Boq
    {
        $boq = Boq::query()
            ->where('project_id', $project->id)
            ->where('is_current', true)
            ->where('status', BoqStatus::Approved)
            ->first();

        if (! $boq) {
            abort(422, 'Project needs a current approved BOQ before variations.');
        }

        return $boq;
    }
}
