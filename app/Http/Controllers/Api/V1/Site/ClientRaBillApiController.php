<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\DeniesRetiredFinanceWrites;
use App\Http\Resources\ClientInvoiceResource;
use App\Models\Core\Company;
use App\Models\Finance\ClientInvoice;
use App\Models\Projects\Project;
use App\Services\Finance\ClientInvoiceService;
use App\Support\Format\IndianNumber;
use App\Support\Pdf\PdfResponder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ClientRaBillApiController extends Controller
{
    use DeniesRetiredFinanceWrites;

    public function __construct(private readonly ClientInvoiceService $invoices) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ClientInvoice::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ClientInvoiceStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $project->clientInvoices()
            ->with('client:id,code,company_name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('ra_sequence')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ClientInvoiceResource::collection($page);
    }

    public function prefill(Request $request, Project $project): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        Gate::authorize('create', [ClientInvoice::class, $project]);

        $data = $request->validate([
            'period_to' => ['required', 'date'],
            'except_id' => ['nullable', 'integer'],
        ]);
        $except = isset($data['except_id'])
            ? ClientInvoice::query()->where('project_id', $project->id)->find($data['except_id'])
            : null;

        return response()->json([
            'data' => [
                'lines' => $this->invoices->billableLines($project, $data['period_to'], $except),
                'advance_balance' => $project->client_id
                    ? $this->invoices->advanceBalance($project->id, (int) $project->client_id, $except?->id)->toMoney()
                    : '0.00',
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        Gate::authorize('create', [ClientInvoice::class, $project]);
        $invoice = $this->invoices->create($project, $this->validated($request), $request->user());

        return (new ClientInvoiceResource($this->detail($invoice)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, ClientInvoice $clientInvoice): ClientInvoiceResource
    {
        $this->assertOnProject($project, $clientInvoice);
        Gate::authorize('view', $clientInvoice);

        return new ClientInvoiceResource($this->detail($clientInvoice));
    }

    public function update(Request $request, Project $project, ClientInvoice $clientInvoice): ClientInvoiceResource
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $clientInvoice);
        Gate::authorize('update', $clientInvoice);
        $invoice = $this->invoices->update($clientInvoice, $this->validated($request), $request->user());

        return new ClientInvoiceResource($this->detail($invoice));
    }

    public function destroy(Project $project, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $clientInvoice);
        Gate::authorize('delete', $clientInvoice);
        $this->invoices->delete($clientInvoice);

        return response()->json(['message' => 'RA bill deleted.']);
    }

    public function submit(Request $request, Project $project, ClientInvoice $clientInvoice): ClientInvoiceResource
    {
        $this->denyRetiredFinanceWrite();
        $this->assertOnProject($project, $clientInvoice);
        Gate::authorize('submit', $clientInvoice);
        $this->invoices->submit($clientInvoice, $request->user());

        return new ClientInvoiceResource($this->detail($clientInvoice->fresh()));
    }

    public function pdf(Request $request, Project $project, ClientInvoice $clientInvoice): Response
    {
        $this->assertOnProject($project, $clientInvoice);
        Gate::authorize('export', $clientInvoice);

        $invoice = $clientInvoice->load(['client', 'taxRate:id,name']);
        $company = Company::query()->findOrFail($project->company_id);
        $items = $invoice->items()->with('unit:id,symbol')->get();
        $name = ($invoice->invoice_number ?? "RA-{$invoice->ra_sequence}-DRAFT").'.pdf';

        $pdf = Pdf::loadView('pdf.client-invoice', [
            'invoice' => $invoice,
            'items' => $items,
            'company' => $company,
            'project' => $project,
            'supplierState' => IndianState::tryFrom((string) $invoice->supplier_state)?->label(),
            'posState' => IndianState::tryFrom((string) $invoice->place_of_supply_state)?->label(),
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'words' => IndianNumber::rupeesInWords((string) $invoice->net_payable),
        ])->setPaper('a4');

        $inline = $request->routeIs('api.v1.projects.client-ra-bills.pdf.view');

        return PdfResponder::send($pdf, str_replace('/', '-', $name), $inline);
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

    private function detail(ClientInvoice $invoice): ClientInvoice
    {
        return $invoice->load([
            'client:id,code,company_name,gstin',
            'taxRate:id,name',
            'items.unit:id,symbol',
            'latestApprovalRequest.submitter:id,name',
            'attachments.uploader:id,name',
        ]);
    }

    private function assertOnProject(Project $project, ClientInvoice $invoice): void
    {
        abort_unless((int) $invoice->project_id === (int) $project->id, 404);
    }
}
