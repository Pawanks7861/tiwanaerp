<?php

namespace App\Http\Controllers\Gate;

use App\Enums\Gate\GatePassDirection;
use App\Enums\Gate\GatePassItemType;
use App\Enums\Gate\GatePassStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Equipment\Equipment;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassItem;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Services\Gate\GatePassService;
use App\Support\Pdf\PdfResponder;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GatePassController extends Controller
{
    public function __construct(private readonly GatePassService $passes) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [GatePass::class, $project]);

        $page = $project->gatePasses()->latest('passed_at')->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('Gate/Index', [
            'project' => ProjectHeader::for($project),
            'passes' => $page->through(fn (GatePass $pass) => [
                'id' => $pass->id,
                'pass_number' => $pass->pass_number,
                'direction' => $pass->direction->value,
                'direction_label' => $pass->direction->label(),
                'returnable' => $pass->returnable,
                'party_name' => $pass->party_name,
                'vehicle_no' => $pass->vehicle_no,
                'purpose' => $pass->purpose,
                'passed_at' => $pass->passed_at?->toIso8601String(),
                'status' => $pass->status->value,
                'status_label' => $pass->status->label(),
            ]),
            'can' => ['create' => $request->user()->can('create', [GatePass::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [GatePass::class, $project]);

        return $this->form($project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [GatePass::class, $project]);
        $pass = $this->passes->create($project, $this->validated($request));

        return redirect()->route('projects.gate-passes.show', [$project, $pass])->with('success', "Gate pass {$pass->pass_number} saved.");
    }

    public function show(Request $request, Project $project, GatePass $gatePass): Response
    {
        Gate::authorize('view', $gatePass);
        $user = $request->user();
        $gatePass->load(['items.unit:id,symbol', 'items.material:id,name', 'items.equipment:id,code,name', 'weighbridgeReceipt', 'approver:id,name', 'verifier:id,name', 'site:id,name', 'vendor:id,name']);

        return Inertia::render('Gate/Show', [
            'project' => ProjectHeader::for($project),
            'pass' => $this->payload($gatePass),
            'items' => $gatePass->items->map(fn (GatePassItem $item) => [
                'id' => $item->id,
                'item_type' => $item->item_type->value,
                'item_type_label' => $item->item_type->label(),
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit?->symbol,
                'weight' => $item->weight,
                'scrap_type' => $item->scrap_type,
                'rate' => $item->rate,
                'destination' => $item->destination,
                'material' => $item->material?->name,
                'equipment' => $item->equipment ? "{$item->equipment->code} · {$item->equipment->name}" : null,
                'remarks' => $item->remarks,
            ])->all(),
            'weighbridge' => $this->weighbridge($gatePass),
            'attachments' => ProcurementPresenter::attachments($gatePass),
            'audit' => AuditPresenter::trail([$gatePass]),
            'can' => [
                'update' => $user->can('update', $gatePass),
                'submit' => $gatePass->isEditable() && $user->can('update', $gatePass),
                'approve' => $gatePass->status === GatePassStatus::Submitted && $user->can('approve', $gatePass),
                'verify' => $gatePass->status === GatePassStatus::Approved && $user->can('verify', $gatePass),
                'return' => $gatePass->returnable && $gatePass->direction === GatePassDirection::Out && $gatePass->status === GatePassStatus::Verified && $user->can('verify', $gatePass),
                'attach' => $user->can('update', $gatePass),
            ],
        ]);
    }

    public function edit(Project $project, GatePass $gatePass): Response
    {
        Gate::authorize('update', $gatePass);

        return $this->form($project, $gatePass);
    }

    public function update(Request $request, Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('update', $gatePass);
        $this->passes->update($gatePass, $this->validated($request));

        return redirect()->route('projects.gate-passes.show', [$project, $gatePass])->with('success', 'Gate pass updated.');
    }

    public function destroy(Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('delete', $gatePass);
        $this->passes->delete($gatePass);

        return redirect()->route('projects.gate-passes.index', $project)->with('success', 'Gate pass deleted.');
    }

    public function submit(Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('update', $gatePass);
        $this->passes->submit($gatePass);

        return back()->with('success', 'Gate pass submitted.');
    }

    public function approve(Request $request, Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('approve', $gatePass);
        $this->passes->approve($gatePass, $request->user());

        return back()->with('success', 'Gate pass approved.');
    }

    public function reject(Request $request, Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('approve', $gatePass);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->passes->reject($gatePass, $reason);

        return back()->with('success', 'Gate pass rejected.');
    }

    public function verify(Request $request, Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('verify', $gatePass);
        $this->passes->verify($gatePass, $request->user());

        return back()->with('success', 'Gate pass verified.');
    }

    public function returned(Request $request, Project $project, GatePass $gatePass): RedirectResponse
    {
        Gate::authorize('verify', $gatePass);
        $this->passes->markReturned($gatePass, $request->user());

        return back()->with('success', 'Return recorded. The gate pass is closed.');
    }

    public function pdf(Request $request, Project $project, GatePass $gatePass): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('view', $gatePass);
        $gatePass->load(['items.unit:id,symbol', 'site:id,name', 'weighbridgeReceipt', 'approver:id,name', 'verifier:id,name']);

        $pdf = Pdf::loadView('pdf.gate-pass', [
            'company' => Company::query()->findOrFail($gatePass->company_id),
            'project' => $project,
            'pass' => $gatePass,
        ]);

        return PdfResponder::send($pdf, $gatePass->pass_number.'.pdf', $request->routeIs('projects.gate-passes.pdf.view'));
    }

    private function form(Project $project, ?GatePass $pass): Response
    {
        $pass?->load(['items', 'weighbridgeReceipt']);

        return Inertia::render('Gate/Form', [
            'project' => ProjectHeader::for($project),
            'pass' => $pass ? $this->payload($pass) : null,
            'items' => $pass?->items->map(fn (GatePassItem $item) => [
                'item_type' => $item->item_type->value,
                'material_id' => $item->material_id,
                'equipment_id' => $item->equipment_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_id' => $item->unit_id,
                'weight' => $item->weight,
                'scrap_type' => $item->scrap_type,
                'rate' => $item->rate,
                'destination' => $item->destination,
                'remarks' => $item->remarks,
            ])->all() ?? [],
            'weighbridge' => $pass ? $this->weighbridge($pass) : null,
            'options' => [
                'directions' => GatePassDirection::options(),
                'itemTypes' => GatePassItemType::options(),
                'sites' => ProcurementPresenter::siteOptions($project),
                'units' => ProcurementPresenter::unitOptions(),
                'materials' => ProcurementPresenter::materialOptions(),
                'vendors' => ProcurementPresenter::vendorOptions(),
                'equipment' => Equipment::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Equipment $e) => ['value' => $e->id, 'label' => $e->name, 'description' => $e->code])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(GatePass $pass): array
    {
        return [
            'id' => $pass->id,
            'pass_number' => $pass->pass_number,
            'direction' => $pass->direction->value,
            'direction_label' => $pass->direction->label(),
            'status' => $pass->status->value,
            'status_label' => $pass->status->label(),
            'returnable' => $pass->returnable,
            'passed_at' => $pass->passed_at?->format('Y-m-d\TH:i'),
            'expected_return_date' => $pass->expected_return_date?->toDateString(),
            'returned_at' => $pass->returned_at?->toIso8601String(),
            'site_id' => $pass->site_id,
            'site' => $pass->site?->name,
            'party_name' => $pass->party_name,
            'vendor_id' => $pass->vendor_id,
            'vendor' => $pass->vendor?->name,
            'vehicle_no' => $pass->vehicle_no,
            'driver_name' => $pass->driver_name,
            'driver_mobile' => $pass->driver_mobile,
            'purpose' => $pass->purpose,
            'reference_type' => $pass->reference_type,
            'reference_no' => $pass->reference_no,
            'remarks' => $pass->remarks,
            'approved_by' => $pass->approver?->name,
            'verified_by' => $pass->verifier?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function weighbridge(GatePass $pass): ?array
    {
        $receipt = $pass->weighbridgeReceipt;
        if ($receipt === null) {
            return null;
        }

        return [
            'id' => $receipt->id,
            ...$receipt->only(['name', 'slip_no', 'vehicle_no', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit', 'manual_reason', 'operator_name', 'remarks']),
            'weighed_at' => $receipt->weighed_at?->format('Y-m-d\TH:i'),
            'attachments' => ProcurementPresenter::attachments($receipt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'site_id' => ['nullable', 'integer'],
            'direction' => ['required', Rule::enum(GatePassDirection::class)],
            'returnable' => ['boolean'],
            'passed_at' => ['required', 'date'],
            'expected_return_date' => ['nullable', 'date'],
            'party_name' => ['nullable', 'string', 'max:150'],
            'vendor_id' => ['nullable', 'integer'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_mobile' => ['nullable', 'string', 'max:20'],
            'purpose' => ['required', 'string', 'max:500'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_type' => ['required', Rule::enum(GatePassItemType::class)],
            'items.*.material_id' => ['nullable', 'integer'],
            'items.*.equipment_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:300'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.weight' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.scrap_type' => ['nullable', 'string', 'max:80'],
            'items.*.rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'items.*.destination' => ['nullable', 'string', 'max:150'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
            ...WeighbridgeReceiptService::rules(),
        ]);
    }
}
