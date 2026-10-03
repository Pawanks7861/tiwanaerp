<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentMode;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Labour\LabourPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentAllocation;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\PayableService;
use App\Services\Finance\PaymentService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments, private readonly PayableService $payables) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Payment::class, $project]);

        $filters = $request->validate([
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $page = $project->payments()
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('payment_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('bank_reference', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('payment_date')->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Finance/Payments/Index', [
            'project' => ProjectHeader::for($project),
            'payments' => $page->through(fn (Payment $p) => $this->header($p)),
            'filters' => $filters,
            'directions' => PaymentDirection::options(),
            'statuses' => PaymentStatus::options(),
            'can' => ['create' => $request->user()->can('create', [Payment::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [Payment::class, $project]);

        $party = PaymentPartyType::tryFrom($request->string('party_type')->toString());

        return $this->form($project, null, $party, $request->integer('party_id') ?: null);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Payment::class, $project]);

        $payment = $this->payments->create($project, $this->validated($request, true));

        return redirect()->route('projects.payments.show', [$project, $payment])->with('success', "{$payment->direction->label()} {$payment->payment_number} saved as draft.");
    }

    public function show(Request $request, Project $project, Payment $payment): Response
    {
        Gate::authorize('view', $payment);
        $user = $request->user();
        $payment->load(['approver:id,name', 'canceller:id,name', 'creator:id,name']);
        $allocations = $payment->allocations()->get();

        return Inertia::render('Finance/Payments/Show', [
            'project' => ProjectHeader::for($project),
            'payment' => [
                ...$this->header($payment),
                ...$payment->only(['bank_reference', 'tds_amount', 'remarks', 'cancellation_reason']),
                'created_by' => $payment->creator?->name,
                'approved_by' => $payment->approver?->name,
                'approved_at' => $payment->approved_at?->toIso8601String(),
                'cancelled_by' => $payment->canceller?->name,
                'cancelled_at' => $payment->cancelled_at?->toIso8601String(),
            ],
            'allocations' => $allocations->map(function (PaymentAllocation $a) {
                $payable = $this->payables->classFor($a->payable_type)::query()->withTrashed()->find($a->payable_id);

                return [
                    'id' => $a->id,
                    'amount' => $a->amount,
                    'document' => $payable ? $this->payables->label($payable) : '—',
                    'due' => $payable ? $this->payables->due($payable)->toMoney() : null,
                    'outstanding' => $payable ? $this->payables->outstanding($payable)->toMoney() : null,
                    'url' => $payable ? $this->payableUrl($a->payable_type, $payable->project_id, $payable->getKey()) : null,
                ];
            })->all(),
            'attachments' => ProcurementPresenter::attachments($payment),
            'can' => $this->abilities($user, $payment),
        ]);
    }

    public function edit(Project $project, Payment $payment): Response
    {
        Gate::authorize('update', $payment);

        return $this->form($project, $payment, $payment->party_type, (int) $payment->party_id);
    }

    public function update(Request $request, Project $project, Payment $payment): RedirectResponse
    {
        Gate::authorize('update', $payment);

        $this->payments->update($payment, $this->validated($request, false));

        return redirect()->route('projects.payments.show', [$project, $payment])->with('success', 'Payment updated.');
    }

    public function destroy(Project $project, Payment $payment): RedirectResponse
    {
        Gate::authorize('delete', $payment);

        $this->payments->delete($payment);

        return redirect()->route('projects.payments.index', $project)->with('success', "{$payment->payment_number} deleted.");
    }

    public function approve(Request $request, Project $project, Payment $payment): RedirectResponse
    {
        Gate::authorize('approve', $payment);

        $this->payments->approve($payment, $request->user());

        return back()->with('success', "{$payment->payment_number} approved; the allocated documents were updated.");
    }

    public function cancel(Request $request, Project $project, Payment $payment): RedirectResponse
    {
        Gate::authorize('cancel', $payment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->payments->cancel($payment, $request->user(), $reason);

        return back()->with('success', "{$payment->payment_number} cancelled; its allocations no longer count.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            ...($creating ? [
                'party_type' => ['required', Rule::enum(PaymentPartyType::class)],
                'party_id' => ['required', 'integer'],
            ] : []),
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'mode' => ['required', Rule::enum(PaymentMode::class)->except([PaymentMode::PettyCash])],
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'tds_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.payable_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ], [], ['party_id' => 'party', 'allocations.*.amount' => 'allocation']);
    }

    private function form(Project $project, ?Payment $payment, ?PaymentPartyType $party, ?int $partyId): Response
    {
        $project->loadMissing('client:id,company_name');

        return Inertia::render('Finance/Payments/Form', [
            'project' => ProjectHeader::for($project),
            'payment' => $payment ? [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                ...$payment->only(['amount', 'tds_amount', 'bank_reference', 'remarks']),
                'mode' => $payment->mode->value,
                'payment_date' => $payment->payment_date?->toDateString(),
                'party_name' => $payment->partyName(),
            ] : null,
            'partyType' => $party?->value,
            'partyId' => $partyId,
            'partyTypes' => PaymentPartyType::options(),
            'parties' => $party ? $this->partyOptions($project, $party) : [],
            'payables' => $party && $partyId ? $this->payments->openPayables($project, $party, $partyId, $payment) : [],
            'modes' => PaymentMode::paymentOptions(),
            'today' => now()->toDateString(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partyOptions(Project $project, PaymentPartyType $party): array
    {
        return match ($party) {
            PaymentPartyType::Client => $project->client ? [['value' => $project->client_id, 'label' => $project->client->company_name]] : [],
            PaymentPartyType::Vendor => ProcurementPresenter::vendorOptions(),
            PaymentPartyType::Subcontractor => Subcontractor::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Subcontractor $s) => ['value' => $s->id, 'label' => $s->name, 'description' => $s->code])->all(),
            PaymentPartyType::LabourPayment => LabourPayment::query()->where('project_id', $project->id)
                ->whereIn('status', [LabourPaymentStatus::Approved, LabourPaymentStatus::Paid])->orderByDesc('id')->get()
                ->filter(fn (LabourPayment $l) => $this->payables->isSettleable($l))
                ->map(fn (LabourPayment $l) => ['value' => $l->id, 'label' => $l->payment_number, 'description' => 'Net '.$l->total_net])->values()->all(),
        };
    }

    private function payableUrl(string $type, int $projectId, int $id): string
    {
        return match ($type) {
            'client_invoice' => route('projects.ra-bills.show', [$projectId, $id]),
            'vendor_bill' => route('projects.vendor-bills.show', [$projectId, $id]),
            'subcontractor_bill' => route('projects.subcontractor-bills.show', [$projectId, $id]),
            default => route('projects.labour-payments.show', [$projectId, $id]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'payment_date' => $payment->payment_date?->toDateString(),
            'direction' => $payment->direction->value,
            'direction_label' => $payment->direction->label(),
            'party_type' => $payment->party_type->value,
            'party_type_label' => $payment->party_type->label(),
            'party_name' => $payment->partyName(),
            'mode' => $payment->mode->value,
            'mode_label' => $payment->mode->label(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'amount' => $payment->amount,
            'allocated' => Decimal::sum($payment->allocations()->pluck('amount')->all())->toMoney(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, Payment $payment): array
    {
        $draft = $payment->status === PaymentStatus::Draft;
        $selfRecorded = (int) $payment->created_by === (int) $user->id;

        return [
            'update' => $draft && $user->can('update', $payment),
            'delete' => $draft && $user->can('delete', $payment),
            'approve' => $draft && ! $selfRecorded && $user->can('approve', $payment),
            'selfRecorded' => $selfRecorded,
            'cancel' => $payment->status === PaymentStatus::Approved && $user->can('cancel', $payment),
            'attach' => $draft && $user->can('update', $payment),
        ];
    }
}
