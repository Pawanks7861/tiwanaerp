<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Core\Company;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\ClientInvoiceItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ClientInvoiceService;
use App\Services\Finance\PayableService;
use App\Support\Format\IndianNumber;
use App\Support\Permissions\CompanyPermission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ClientInvoiceController extends Controller
{
    public function __construct(private readonly ClientInvoiceService $invoices, private readonly PayableService $payables) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ClientInvoice::class, $project]);

        $filters = $request->validate(['status' => ['nullable', Rule::enum(ClientInvoiceStatus::class)]]);
        $page = $project->clientInvoices()->with('client:id,company_name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('ra_sequence')->paginate(25)->withQueryString();

        return Inertia::render('Finance/ClientBills/Index', [
            'project' => ProjectHeader::for($project),
            'bills' => $page->through(fn (ClientInvoice $i) => $this->header($i)),
            'filters' => $filters,
            'statuses' => ClientInvoiceStatus::options(),
            'can' => ['create' => $request->user()->can('create', [ClientInvoice::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [ClientInvoice::class, $project]);

        return $this->form($request, $project, null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [ClientInvoice::class, $project]);

        $invoice = $this->invoices->create($project, $this->validated($request), $request->user());

        return redirect()->route('projects.ra-bills.show', [$project, $invoice])->with('success', "RA bill {$invoice->ra_sequence} saved as draft.");
    }

    public function show(Request $request, Project $project, ClientInvoice $clientInvoice): Response
    {
        Gate::authorize('view', $clientInvoice);
        $user = $request->user();
        $invoice = $clientInvoice->load(['client:id,code,company_name,gstin,state_code', 'taxRate:id,name', 'certifier:id,name', 'creator:id,name']);
        $items = $invoice->items()->with('unit:id,symbol')->get();

        return Inertia::render('Finance/ClientBills/Show', [
            'project' => ProjectHeader::for($project),
            'bill' => [
                ...$this->header($invoice),
                ...$invoice->only(['cgst_amount', 'sgst_amount', 'igst_amount', 'tax_amount', 'retention_percent', 'retention_amount',
                    'advance_recovery', 'tds_percent', 'tds_amount', 'other_deductions', 'remarks', 'supplier_state', 'place_of_supply_state']),
                'tax_type' => $invoice->tax_type->value,
                'tax_rate' => $invoice->taxRate?->name,
                'period_from' => $invoice->period_from?->toDateString(),
                'period_to' => $invoice->period_to?->toDateString(),
                'client' => $invoice->client?->only(['id', 'code', 'company_name', 'gstin']),
                'retention_released' => $this->payables->releasedRetention($invoice)->toMoney(),
                'due' => $this->payables->due($invoice)->toMoney(),
                'outstanding' => $invoice->isCertified() ? $this->payables->outstanding($invoice)->toMoney() : null,
                'created_by' => $invoice->creator?->name,
                'certified_by' => $invoice->certifier?->name,
                'certified_at' => $invoice->certified_at?->toIso8601String(),
            ],
            'items' => $items->map(fn (ClientInvoiceItem $i) => [
                'id' => $i->id,
                ...$i->only(['item_code', 'description', 'boq_qty', 'executed_qty', 'previous_qty', 'current_qty', 'cumulative_qty', 'rate', 'current_amount', 'is_override', 'override_reason']),
                'unit' => $i->unit?->symbol,
            ])->all(),
            'approval' => ProcurementPresenter::approval($invoice, $user),
            'attachments' => ProcurementPresenter::attachments($invoice),
            'audit' => AuditPresenter::trail([$invoice]),
            'can' => $this->abilities($user, $invoice),
        ]);
    }

    public function edit(Request $request, Project $project, ClientInvoice $clientInvoice): Response
    {
        Gate::authorize('update', $clientInvoice);

        return $this->form($request, $project, $clientInvoice);
    }

    public function update(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        Gate::authorize('update', $clientInvoice);

        $this->invoices->update($clientInvoice, $this->validated($request), $request->user());

        return redirect()->route('projects.ra-bills.show', [$project, $clientInvoice])->with('success', 'RA bill updated.');
    }

    public function destroy(Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        Gate::authorize('delete', $clientInvoice);

        $this->invoices->delete($clientInvoice);

        return redirect()->route('projects.ra-bills.index', $project)->with('success', "RA bill {$clientInvoice->ra_sequence} deleted.");
    }

    public function submit(Request $request, Project $project, ClientInvoice $clientInvoice): RedirectResponse
    {
        Gate::authorize('submit', $clientInvoice);

        $this->invoices->submit($clientInvoice, $request->user());

        return back()->with('success', 'RA bill submitted for certification.');
    }

    public function pdf(Project $project, ClientInvoice $clientInvoice): HttpResponse
    {
        Gate::authorize('export', $clientInvoice);
        $invoice = $clientInvoice->load(['client', 'taxRate:id,name']);
        $company = Company::query()->findOrFail($project->company_id);
        $items = $invoice->items()->with('unit:id,symbol')->get();
        $name = ($invoice->invoice_number ?? "RA-{$invoice->ra_sequence}-DRAFT").'.pdf';

        return Pdf::loadView('pdf.client-invoice', [
            'invoice' => $invoice,
            'items' => $items,
            'company' => $company,
            'project' => $project,
            'supplierState' => IndianState::tryFrom((string) $invoice->supplier_state)?->label(),
            'posState' => IndianState::tryFrom((string) $invoice->place_of_supply_state)?->label(),
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'words' => IndianNumber::rupeesInWords((string) $invoice->net_payable),
        ])->setPaper('a4')->download(str_replace('/', '-', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'tax_rate_id' => ['nullable', 'integer'],
            'retention_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'tds_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.boq_item_id' => ['required', 'integer'],
            'items.*.current_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.override_reason' => ['nullable', 'string', 'max:500'],
        ], [], ['items.*.current_qty' => 'quantity this bill']);
    }

    private function form(Request $request, Project $project, ?ClientInvoice $invoice): Response
    {
        $periodTo = $request->date('period_to')?->toDateString() ?? $invoice?->period_to?->toDateString() ?? now()->toDateString();
        $existing = $invoice ? $invoice->items()->get()->keyBy('boq_line_uid') : collect();
        $last = $project->clientInvoices()->whereIn('status', ClientInvoiceStatus::certifiedStates())->orderByDesc('ra_sequence')->first();
        $project->loadMissing('client:id,company_name,state_code');
        $user = $request->user();

        return Inertia::render('Finance/ClientBills/Form', [
            'project' => ProjectHeader::for($project),
            'bill' => $invoice ? [
                'id' => $invoice->id,
                'ra_sequence' => $invoice->ra_sequence,
                ...$invoice->only(['tax_rate_id', 'retention_percent', 'tds_percent', 'advance_recovery', 'other_deductions', 'remarks']),
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'period_from' => $invoice->period_from?->toDateString(),
                'period_to' => $invoice->period_to?->toDateString(),
                'tax_type' => $invoice->tax_type->value,
            ] : null,
            'defaults' => [
                'period_from' => $last?->period_to?->addDay()->toDateString() ?? ($project->start_date?->toDateString() ?? now()->startOfMonth()->toDateString()),
                'period_to' => $periodTo,
                'retention_percent' => $last?->retention_percent,
                'tds_percent' => $last?->tds_percent,
                'tax_rate_id' => $last?->tax_rate_id,
            ],
            'client' => $project->client ? ['name' => $project->client->company_name] : null,
            'taxType' => $this->taxTypePreview($project),
            'lines' => array_map(fn (array $line) => [
                ...$line,
                'current_qty' => $existing->get($line['boq_line_uid'])?->current_qty,
                'override_reason' => $existing->get($line['boq_line_uid'])?->override_reason,
            ], $this->invoices->billableLines($project, $periodTo, $invoice)),
            'advanceBalance' => $project->client_id ? $this->invoices->advanceBalance($project->id, (int) $project->client_id, $invoice?->id)->toMoney() : '0.00',
            'taxRates' => ProcurementPresenter::taxRateOptions(),
            'canOverride' => CompanyPermission::check($user, (int) $project->company_id, 'billing.override_qty'),
            'today' => now()->toDateString(),
        ]);
    }

    private function taxTypePreview(Project $project): ?string
    {
        $supplier = Company::query()->whereKey($project->company_id)->value('state_code');
        if (blank($supplier) || blank($project->state_code)) {
            return null;
        }

        return $supplier === $project->state_code ? 'intra' : 'inter';
    }

    /**
     * @return array<string, mixed>
     */
    private function header(ClientInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'ra_sequence' => $invoice->ra_sequence,
            'invoice_number' => $invoice->invoice_number,
            'display_number' => $invoice->displayNumber(),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'client_name' => $invoice->client?->company_name,
            'gross_amount' => $invoice->gross_amount,
            'invoice_total' => $invoice->invoice_total,
            'net_payable' => $invoice->net_payable,
            'received_amount' => $invoice->received_amount,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, ClientInvoice $invoice): array
    {
        $editable = $invoice->isEditable();

        return [
            'update' => $editable && $user->can('update', $invoice),
            'delete' => $invoice->status === ClientInvoiceStatus::Draft && $user->can('delete', $invoice) && ! $invoice->approvalRequests()->exists(),
            'submit' => $editable && $user->can('submit', $invoice),
            'export' => $user->can('export', $invoice),
            'attach' => $editable && $user->can('update', $invoice),
        ];
    }
}
