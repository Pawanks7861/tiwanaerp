<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\PaymentDirection;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Finance\Payment;
use App\Models\Projects\Project;
use App\Services\Finance\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PaymentApiController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function indexReceipts(Request $request, Project $project): AnonymousResourceCollection
    {
        $request->merge(['direction' => PaymentDirection::Receipt->value]);

        return $this->index($request, $project);
    }

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Payment::class, $project]);

        $filters = $request->validate([
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'party_type' => ['nullable', Rule::enum(PaymentPartyType::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $page = $project->payments()
            ->with(['allocations'])
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($filters['party_type'] ?? null, fn ($q, $t) => $q->where('party_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('payment_number', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('payment_date')->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return PaymentResource::collection($page);
    }

    public function openPayables(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Payment::class, $project]);

        $data = $request->validate([
            'party_type' => ['required', Rule::enum(PaymentPartyType::class)],
            'party_id' => ['required', 'integer'],
            'payment_id' => ['nullable', 'integer'],
        ]);
        $current = isset($data['payment_id'])
            ? Payment::query()->where('project_id', $project->id)->find($data['payment_id'])
            : null;

        return response()->json([
            'data' => $this->payments->openPayables(
                $project,
                PaymentPartyType::from($data['party_type']),
                (int) $data['party_id'],
                $current,
            ),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Payment::class, $project]);
        $payment = $this->payments->create($project, $this->validated($request, true));

        return (new PaymentResource($this->detail($payment)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, Payment $payment): PaymentResource
    {
        $this->assertOnProject($project, $payment);
        Gate::authorize('view', $payment);

        return new PaymentResource($this->detail($payment));
    }

    public function update(Request $request, Project $project, Payment $payment): PaymentResource
    {
        $this->assertOnProject($project, $payment);
        Gate::authorize('update', $payment);
        $updated = $this->payments->update($payment, $this->validated($request, false));

        return new PaymentResource($this->detail($updated));
    }

    public function destroy(Project $project, Payment $payment): JsonResponse
    {
        $this->assertOnProject($project, $payment);
        Gate::authorize('delete', $payment);
        $this->payments->delete($payment);

        return response()->json(['message' => 'Payment deleted.']);
    }

    public function approve(Request $request, Project $project, Payment $payment): PaymentResource
    {
        $this->assertOnProject($project, $payment);
        Gate::authorize('approve', $payment);
        $this->payments->approve($payment, $request->user());

        return new PaymentResource($this->detail($payment->fresh()));
    }

    public function cancel(Request $request, Project $project, Payment $payment): PaymentResource
    {
        $this->assertOnProject($project, $payment);
        Gate::authorize('cancel', $payment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->payments->cancel($payment, $request->user(), $reason);

        return new PaymentResource($this->detail($payment->fresh()));
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
            'mode' => ['required', Rule::enum(\App\Enums\Finance\PaymentMode::class)->except([\App\Enums\Finance\PaymentMode::PettyCash])],
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'tds_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.payable_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ], [], ['party_id' => 'party', 'allocations.*.amount' => 'allocation']);
    }

    private function detail(Payment $payment): Payment
    {
        return $payment->load(['allocations']);
    }

    private function assertOnProject(Project $project, Payment $payment): void
    {
        abort_unless((int) $payment->project_id === (int) $project->id, 404);
    }
}
