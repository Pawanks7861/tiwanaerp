<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\LeadActivityType;
use App\Enums\Crm\LeadStatus;
use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\Quotation;
use App\Models\User;
use App\Rules\CompanyMember;
use App\Services\Crm\LeadService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Lead::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'assigned_to' => ['nullable', 'integer'],
        ]);
        $page = Lead::query()->with('assignee:id,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['assigned_to'] ?? null, fn ($q, $a) => $q->where('assigned_to', $a))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('lead_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('company_name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('mobile', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Crm/Leads/Index', [
            'leads' => $page->through(fn (Lead $l) => $this->header($l)),
            'filters' => $filters,
            'statuses' => LeadStatus::options(),
            'assignees' => $this->assignees(),
            'can' => ['create' => $request->user()->can('create', Lead::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Lead::class);

        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Lead::class);

        $lead = $this->leads->create($this->validated($request));

        return redirect()->route('crm.leads.show', $lead)->with('success', "Lead {$lead->lead_number} created.");
    }

    public function show(Request $request, Lead $lead): Response
    {
        Gate::authorize('view', $lead);
        $user = $request->user();
        $lead->load(['assignee:id,name', 'client:id,code,company_name', 'creator:id,name']);

        return Inertia::render('Crm/Leads/Show', [
            'lead' => [
                ...$this->header($lead),
                ...$lead->only(['email', 'source', 'project_type', 'location', 'state_code', 'lost_reason', 'notes']),
                'expected_close_date' => $lead->expected_close_date?->toDateString(),
                'state' => IndianState::tryFrom((string) $lead->state_code)?->label(),
                'client' => $lead->client?->only(['id', 'code', 'company_name']),
                'created_by' => $lead->creator?->name,
            ],
            'activities' => $lead->activities()->with('creator:id,name')->limit(200)->get()->map(fn (LeadActivity $a) => [
                'id' => $a->id,
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'activity_at' => $a->activity_at?->toIso8601String(),
                'summary' => $a->summary,
                'next_follow_up' => $a->next_follow_up?->toDateString(),
                'by' => $a->creator?->name,
            ])->all(),
            'quotations' => $user->can('crm.quotations.view') ? $lead->quotations()->latest('id')->get()->map(fn (Quotation $q) => [
                'id' => $q->id,
                'number' => $q->displayNumber(),
                'title' => $q->title,
                'status' => $q->status->value,
                'status_label' => $q->status->label(),
                'total_amount' => $q->total_amount,
            ])->all() : [],
            'statuses' => LeadStatus::options(),
            'activityTypes' => LeadActivityType::options(),
            'can' => [
                'update' => $user->can('update', $lead),
                'delete' => ! $lead->quotations()->exists() && $user->can('delete', $lead),
                'quote' => $user->can('create', Quotation::class) && ! $lead->status->isClosed(),
            ],
            'now' => now()->format('Y-m-d\TH:i'),
        ]);
    }

    public function edit(Lead $lead): Response
    {
        Gate::authorize('update', $lead);

        return $this->form($lead);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('update', $lead);

        $this->leads->update($lead, $this->validated($request));

        return redirect()->route('crm.leads.show', $lead)->with('success', 'Lead updated.');
    }

    public function status(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('update', $lead);

        $data = $request->validate([
            'status' => ['required', Rule::enum(LeadStatus::class)],
            'lost_reason' => ['nullable', 'string', 'max:500', 'required_if:status,lost'],
        ]);
        $this->leads->changeStatus($lead, LeadStatus::from($data['status']), $data['lost_reason'] ?? null);

        return back()->with('success', 'Lead status updated.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        Gate::authorize('delete', $lead);

        $this->leads->delete($lead);

        return redirect()->route('crm.leads.index')->with('success', "Lead {$lead->lead_number} deleted.");
    }

    public function storeActivity(Request $request, Lead $lead): RedirectResponse
    {
        Gate::authorize('update', $lead);

        $data = $request->validate([
            'type' => ['required', Rule::enum(LeadActivityType::class)],
            'activity_at' => ['required', 'date', 'before_or_equal:now'],
            'summary' => ['required', 'string', 'max:1000'],
            'next_follow_up' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $this->leads->addActivity($lead, $data, $request->user());

        return back()->with('success', 'Activity logged.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'project_type' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:200'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'estimated_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'expected_close_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', new CompanyMember],
            'client_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['assigned_to' => 'assignee']);
    }

    private function form(?Lead $lead): Response
    {
        return Inertia::render('Crm/Leads/Form', [
            'lead' => $lead ? [
                'id' => $lead->id,
                'lead_number' => $lead->lead_number,
                ...$lead->only(['name', 'company_name', 'mobile', 'email', 'source', 'project_type', 'location', 'state_code', 'estimated_value', 'assigned_to', 'client_id', 'notes']),
                'expected_close_date' => $lead->expected_close_date?->toDateString(),
            ] : null,
            'assignees' => $this->assignees(),
            'clients' => Client::query()->where('is_active', true)->orderBy('company_name')->get(['id', 'code', 'company_name'])
                ->map(fn (Client $c) => ['value' => $c->id, 'label' => $c->company_name, 'description' => $c->code])->all(),
            'states' => IndianState::options(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignees(): array
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
    private function header(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'lead_number' => $lead->lead_number,
            'name' => $lead->name,
            'company_name' => $lead->company_name,
            'mobile' => $lead->mobile,
            'status' => $lead->status->value,
            'status_label' => $lead->status->label(),
            'estimated_value' => $lead->estimated_value,
            'assignee' => $lead->assignee?->name,
        ];
    }
}
