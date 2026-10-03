<?php

namespace App\Http\Controllers\Labour;

use App\Enums\Labour\LabourPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Labour\LabourPaymentLine;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Labour\LabourAdvanceService;
use App\Services\Labour\LabourPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LabourPaymentController extends Controller
{
    public function __construct(
        private readonly LabourPaymentService $payments,
        private readonly LabourAdvanceService $advances,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [LabourPayment::class, $project]);
        $user = $request->user();

        $filters = $request->validate(['status' => ['nullable', Rule::enum(LabourPaymentStatus::class)]]);

        $page = $project->labourPayments()
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        $unpaid = LabourAttendance::query()->where('project_id', $project->id)
            ->where('approval_status', 'approved')->whereNull('labour_payment_id');

        return Inertia::render('Labour/Payments/Index', [
            'project' => ProjectHeader::for($project),
            'payments' => $page->through(fn (LabourPayment $p) => [
                ...$this->header($p),
                'lines_count' => $p->lines_count,
            ]),
            'filters' => $filters,
            'statuses' => LabourPaymentStatus::options(),
            'unpaid' => [
                'rows' => (clone $unpaid)->count(),
                'from' => (clone $unpaid)->min('attendance_date') ? substr((string) (clone $unpaid)->min('attendance_date'), 0, 10) : null,
                'to' => (clone $unpaid)->max('attendance_date') ? substr((string) (clone $unpaid)->max('attendance_date'), 0, 10) : null,
            ],
            'today' => now()->toDateString(),
            'can' => ['create' => $user->can('create', [LabourPayment::class, $project])],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [LabourPayment::class, $project]);

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->payments->create($project, $data);

        return redirect()->route('projects.labour-payments.show', [$project, $payment])
            ->with('success', "Payment {$payment->payment_number} created from approved attendance.");
    }

    public function show(Request $request, Project $project, LabourPayment $labourPayment): Response
    {
        Gate::authorize('view', $labourPayment);
        $user = $request->user();
        $payment = $labourPayment->load(['submitter:id,name', 'approver:id,name', 'payer:id,name', 'creator:id,name']);
        $editable = $payment->isEditable();

        $lines = $payment->lines()->with(['labour:id,code,name,labour_trade_id', 'labour.trade:id,name'])->get();

        return Inertia::render('Labour/Payments/Show', [
            'project' => ProjectHeader::for($project),
            'payment' => [
                ...$this->header($payment),
                'remarks' => $payment->remarks,
                'return_reason' => $payment->return_reason,
                'created_by' => $payment->creator?->name,
                'submitted_by' => $payment->submitter?->name,
                'submitted_at' => $payment->submitted_at?->toIso8601String(),
                'approved_by' => $payment->approver?->name,
                'approved_at' => $payment->approved_at?->toIso8601String(),
                'paid_by' => $payment->payer?->name,
                'paid_on' => $payment->paid_on?->toDateString(),
                'payment_reference' => $payment->payment_reference,
                'total_ot' => $payment->total_ot,
                'total_deductions' => $payment->total_deductions,
            ],
            'lines' => $lines->map(fn (LabourPaymentLine $l) => [
                'id' => $l->id,
                'labour' => $l->labour ? ['code' => $l->labour->code, 'name' => $l->labour->name, 'trade' => $l->labour->trade?->name] : null,
                ...$l->only(['present_days', 'half_days', 'ot_hours', 'gross_wage', 'ot_amount', 'advance_recovery', 'other_deductions', 'net_amount', 'remarks']),
                'recoverable' => $editable ? $this->advances->recoverable($l->labour_id, $payment->id)->toMoney() : null,
            ])->all(),
            'days' => $payment->attendance()->with('labour:id,code')->orderBy('attendance_date')->orderBy('labour_id')->get()
                ->map(fn (LabourAttendance $a) => [
                    'id' => $a->id,
                    'date' => $a->attendance_date?->toDateString(),
                    'labour' => $a->labour?->code,
                    'status' => $a->status->label(),
                    'ot_hours' => $a->ot_hours,
                    'wage_amount' => $a->wage_amount,
                    'ot_amount' => $a->ot_amount,
                ])->all(),
            'overlaps' => $this->payments->overlapping($payment)->map(fn (LabourPayment $p) => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'period_from' => $p->period_from?->toDateString(),
                'period_to' => $p->period_to?->toDateString(),
                'status_label' => $p->status->label(),
            ])->all(),
            'today' => now()->toDateString(),
            'can' => $this->abilities($user, $payment),
        ]);
    }

    public function update(Request $request, Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('update', $labourPayment);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'max:1000'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'lines.*.other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->payments->updateLines($labourPayment, $data['lines'], $data['remarks'] ?? null);

        return back()->with('success', 'Deductions saved.');
    }

    public function destroy(Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('delete', $labourPayment);

        $this->payments->delete($labourPayment);

        return redirect()->route('projects.labour-payments.index', $project)
            ->with('success', "Payment {$labourPayment->payment_number} deleted; its attendance is available again.");
    }

    public function submit(Request $request, Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('submit', $labourPayment);

        $this->payments->submit($labourPayment, $request->user());

        return back()->with('success', 'Payment submitted for approval.');
    }

    public function approve(Request $request, Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('approve', $labourPayment);

        $this->payments->approve($labourPayment, $request->user());

        return back()->with('success', 'Payment approved. Advance recoveries are now applied.');
    }

    public function sendBack(Request $request, Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('sendBack', $labourPayment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];

        $this->payments->sendBack($labourPayment, $request->user(), $reason);

        return back()->with('success', 'Payment returned to draft.');
    }

    public function markPaid(Request $request, Project $project, LabourPayment $labourPayment): RedirectResponse
    {
        Gate::authorize('markPaid', $labourPayment);

        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->payments->markPaid($labourPayment, $request->user(), $data);

        return back()->with('success', 'Payment marked as paid.');
    }

    /**
     * @return array<string, mixed>
     */
    private function header(LabourPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'period_from' => $payment->period_from?->toDateString(),
            'period_to' => $payment->period_to?->toDateString(),
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'total_gross' => $payment->total_gross,
            'total_net' => $payment->total_net,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user, LabourPayment $payment): array
    {
        $editable = $payment->isEditable();
        $submitted = $payment->status === LabourPaymentStatus::Submitted;

        return [
            'update' => $editable && $user->can('update', $payment),
            'delete' => $editable && $user->can('delete', $payment),
            'submit' => $editable && $user->can('submit', $payment),
            'approve' => $submitted && (int) $payment->submitted_by !== (int) $user->id && $user->can('approve', $payment),
            'send_back' => $submitted && $user->can('sendBack', $payment),
            'mark_paid' => $payment->status === LabourPaymentStatus::Approved && $user->can('markPaid', $payment),
        ];
    }
}
