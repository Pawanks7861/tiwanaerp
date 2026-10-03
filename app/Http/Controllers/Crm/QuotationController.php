<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\QuotationStatus;
use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Models\Core\Company;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Quotation;
use App\Models\Crm\QuotationItem;
use App\Models\User;
use App\Rules\CompanyMember;
use App\Services\Crm\QuotationService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Quotation::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(QuotationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $page = Quotation::query()->with(['lead:id,lead_number,name,company_name', 'client:id,company_name', 'convertedProject:id,code'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('quotation_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('project_name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Crm/Quotations/Index', [
            'quotations' => $page->through(fn (Quotation $q) => $this->header($q)),
            'filters' => $filters,
            'statuses' => QuotationStatus::options(),
            'can' => ['create' => $request->user()->can('create', Quotation::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Quotation::class);

        $leadId = $request->integer('lead_id') ?: null;
        $lead = $leadId ? Lead::query()->find($leadId) : null;

        return $this->form(null, $lead);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Quotation::class);

        $quotation = $this->quotations->create($this->validated($request));

        return redirect()->route('crm.quotations.show', $quotation)->with('success', "Quotation {$quotation->displayNumber()} saved as draft.");
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        Gate::authorize('view', $quotation);
        $user = $request->user();
        $quotation->load([
            'lead:id,lead_number,name,company_name', 'client:id,code,company_name', 'convertedProject:id,company_id,code,name,project_manager_id',
            'items.unit:id,symbol', 'items.taxRate:id,name', 'creator:id,name', 'decider:id,name', 'parent:id,quotation_number,revision',
        ]);

        $versions = Quotation::query()->where('quotation_number', $quotation->quotation_number)->orderBy('revision')
            ->get(['id', 'quotation_number', 'revision', 'status', 'total_amount']);
        $can = $this->abilities($user, $quotation);

        return Inertia::render('Crm/Quotations/Show', [
            'quotation' => [
                ...$this->header($quotation),
                ...$quotation->only(['project_type', 'site_address', 'city', 'terms', 'rejection_reason',
                    'subtotal', 'discount_amount', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount']),
                'place_of_supply' => IndianState::tryFrom((string) $quotation->place_of_supply_state)?->label(),
                'tax_type' => $quotation->tax_type?->value,
                'sent_at' => $quotation->sent_at?->toIso8601String(),
                'decided_at' => $quotation->decided_at?->toIso8601String(),
                'decided_by' => $quotation->decider?->name,
                'created_by' => $quotation->creator?->name,
                'converted_at' => $quotation->converted_at?->toIso8601String(),
                'converted_project' => $quotation->convertedProject ? [
                    'id' => $quotation->convertedProject->id,
                    'label' => "{$quotation->convertedProject->code} · {$quotation->convertedProject->name}",
                    'url' => $user->can('view', $quotation->convertedProject) ? route('projects.show', $quotation->convertedProject) : null,
                ] : null,
                'items' => $quotation->items->map(fn (QuotationItem $i) => [
                    'id' => $i->id,
                    ...$i->only(['description', 'hsn_sac', 'quantity', 'rate', 'discount_percent', 'taxable_amount',
                        'cgst_amount', 'sgst_amount', 'igst_amount', 'amount']),
                    'unit' => $i->unit?->symbol,
                    'tax_rate' => $i->taxRate?->name,
                ])->all(),
            ],
            'versions' => $versions->map(fn (Quotation $v) => [
                'id' => $v->id,
                'number' => $v->displayNumber(),
                'status_label' => $v->status->label(),
                'total_amount' => $v->total_amount,
                'current' => $v->id === $quotation->id,
            ])->all(),
            'attachments' => ProcurementPresenter::attachments($quotation),
            'convert' => $can['convert'] ? [
                'managers' => $this->members(),
                'today' => now()->toDateString(),
            ] : null,
            'can' => $can,
        ]);
    }

    /**
     * UI abilities. State is checked here as well as in the policy, because Gate::before grants
     * platform super admins every ability regardless of the quotation state.
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, Quotation $quotation): array
    {
        $draft = $quotation->status === QuotationStatus::Draft;

        return [
            'update' => $draft && $user->can('update', $quotation),
            'delete' => $draft && $quotation->revision === 0 && $user->can('delete', $quotation),
            'send' => $draft && $user->can('send', $quotation),
            'decide' => $quotation->status === QuotationStatus::Sent && $user->can('decide', $quotation),
            'revise' => $quotation->status->isRevisable() && $user->can('revise', $quotation),
            'convert' => $quotation->status === QuotationStatus::Accepted && $quotation->converted_project_id === null
                && $user->can('convert', $quotation),
            'attach' => $draft && $user->can('update', $quotation),
        ];
    }

    public function edit(Quotation $quotation): Response
    {
        Gate::authorize('update', $quotation);

        return $this->form($quotation->load('items'), null);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        $this->quotations->update($quotation, $this->validated($request));

        return redirect()->route('crm.quotations.show', $quotation)->with('success', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        Gate::authorize('delete', $quotation);

        $this->quotations->delete($quotation);

        return redirect()->route('crm.quotations.index')->with('success', "Quotation {$quotation->displayNumber()} deleted.");
    }

    public function send(Quotation $quotation): RedirectResponse
    {
        Gate::authorize('send', $quotation);

        $this->quotations->send($quotation);

        return back()->with('success', 'Quotation marked as sent.');
    }

    public function accept(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('decide', $quotation);

        $this->quotations->accept($quotation, $request->user());

        return back()->with('success', 'Quotation accepted.');
    }

    public function reject(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('decide', $quotation);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->quotations->reject($quotation, $request->user(), $data['reason']);

        return back()->with('success', 'Quotation rejected.');
    }

    public function expire(Request $request, Quotation $quotation): RedirectResponse
    {
        Gate::authorize('decide', $quotation);

        $this->quotations->expire($quotation, $request->user());

        return back()->with('success', 'Quotation marked as expired.');
    }

    public function revise(Quotation $quotation): RedirectResponse
    {
        Gate::authorize('revise', $quotation);

        $revision = $this->quotations->revise($quotation);

        return redirect()->route('crm.quotations.edit', $revision)->with('success', "Revision {$revision->displayNumber()} opened as a draft.");
    }

    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        $user = $request->user();
        if ($quotation->converted_project_id !== null && $user->can('crm.quotations.convert')) {
            return redirect()->route('crm.quotations.show', $quotation)->with('success', 'This quotation was already converted.');
        }
        Gate::authorize('convert', $quotation);

        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                Rule::unique('projects', 'code')->where('company_id', app(CurrentCompany::class)->id())],
            'project_manager_id' => ['nullable', new CompanyMember],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ], [], ['project_manager_id' => 'project manager', 'expected_end_date' => 'expected completion date']);
        $data['code'] = filled($data['code'] ?? null) ? $data['code'] : null;

        $project = $this->quotations->convert($quotation, $user, $data);

        return redirect()->route('crm.quotations.show', $quotation)->with('success', "Project {$project->code} created from this quotation.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'title' => ['required', 'string', 'max:200'],
            'project_name' => ['required', 'string', 'max:200'],
            'project_type' => ['nullable', 'string', 'max:50'],
            'site_address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'place_of_supply_state' => ['required', Rule::enum(IndianState::class)],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999'],
            'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'items.*.tax_rate_id' => ['nullable', 'integer'],
        ], [], ['place_of_supply_state' => 'place of supply']);
    }

    private function form(?Quotation $quotation, ?Lead $lead): Response
    {
        $company = Company::query()->find(app(CurrentCompany::class)->id(), ['id', 'state_code']);

        return Inertia::render('Crm/Quotations/Form', [
            'quotation' => $quotation ? [
                'id' => $quotation->id,
                'number' => $quotation->displayNumber(),
                ...$quotation->only(['lead_id', 'client_id', 'title', 'project_name', 'project_type', 'site_address', 'city', 'place_of_supply_state', 'terms']),
                'quotation_date' => $quotation->quotation_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'items' => $quotation->items->map(fn (QuotationItem $i) => $i->only(['description', 'hsn_sac', 'unit_id', 'quantity', 'rate', 'discount_percent', 'tax_rate_id']))->all(),
            ] : null,
            'defaults' => [
                'lead_id' => $lead?->id,
                'client_id' => $lead?->client_id,
                'project_name' => $lead ? trim(($lead->project_type ? "{$lead->project_type} – " : '').($lead->company_name ?: $lead->name)) : null,
                'project_type' => $lead?->project_type,
                'site_address' => $lead?->location,
                'place_of_supply_state' => $lead?->state_code ?? $company?->state_code,
                'quotation_date' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
            ],
            'leads' => Lead::query()
                ->where(fn ($q) => $q->whereNotIn('status', ['won', 'lost'])->orWhere('id', $quotation?->lead_id ?? $lead?->id ?? 0))
                ->orderByDesc('id')->limit(500)->get(['id', 'lead_number', 'name', 'company_name'])
                ->map(fn (Lead $l) => ['value' => $l->id, 'label' => "{$l->lead_number} · ".($l->company_name ?: $l->name), 'description' => $l->name])->all(),
            'clients' => Client::query()->where('is_active', true)->orderBy('company_name')->get(['id', 'code', 'company_name'])
                ->map(fn (Client $c) => ['value' => $c->id, 'label' => $c->company_name, 'description' => $c->code])->all(),
            'states' => IndianState::options(),
            'companyState' => $company?->state_code,
            'units' => ProcurementPresenter::unitOptions(),
            'taxRates' => ProcurementPresenter::taxRateOptions(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'revision' => $quotation->revision,
            'number' => $quotation->displayNumber(),
            'title' => $quotation->title,
            'project_name' => $quotation->project_name,
            'quotation_date' => $quotation->quotation_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'party' => $quotation->client?->company_name ?? ($quotation->lead ? ($quotation->lead->company_name ?: $quotation->lead->name) : null),
            'lead' => $quotation->lead ? ['id' => $quotation->lead->id, 'number' => $quotation->lead->lead_number] : null,
            'status' => $quotation->status->value,
            'status_label' => $quotation->status->label(),
            'total_amount' => $quotation->total_amount,
            'converted_project_code' => $quotation->convertedProject?->code,
        ];
    }
}
