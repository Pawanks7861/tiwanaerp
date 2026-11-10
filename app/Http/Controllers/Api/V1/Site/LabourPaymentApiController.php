<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourPaymentResource;
use App\Models\Labour\LabourPayment;
use App\Models\Projects\Project;
use App\Services\Labour\LabourPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LabourPaymentApiController extends Controller
{
    public function __construct(private readonly LabourPaymentService $payments) {}

    public function prefill(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [LabourPayment::class, $project]);

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from', 'before_or_equal:today'],
        ]);

        return response()->json([
            'data' => $this->payments->prefill($project, $data['period_from'], $data['period_to']),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [LabourPayment::class, $project]);

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->payments->create($project, $data);

        return (new LabourPaymentResource($this->detail($payment)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('view', $labourPayment);

        return new LabourPaymentResource($this->detail($labourPayment));
    }

    public function update(Request $request, Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('update', $labourPayment);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'max:1000'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.advance_recovery' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'lines.*.other_deductions' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payments->updateLines($labourPayment, $data['lines'], $data['remarks'] ?? null);

        return new LabourPaymentResource($this->detail($payment));
    }

    public function destroy(Project $project, LabourPayment $labourPayment): JsonResponse
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $labourPayment);
        $this->payments->delete($labourPayment);

        return response()->json(['message' => 'Payment deleted.']);
    }

    public function submit(Request $request, Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('submit', $labourPayment);
        $this->payments->submit($labourPayment, $request->user());

        return new LabourPaymentResource($this->detail($labourPayment->fresh()));
    }

    public function approve(Request $request, Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('approve', $labourPayment);
        $this->payments->approve($labourPayment, $request->user());

        return new LabourPaymentResource($this->detail($labourPayment->fresh()));
    }

    public function sendBack(Request $request, Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('sendBack', $labourPayment);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->payments->sendBack($labourPayment, $request->user(), $reason);

        return new LabourPaymentResource($this->detail($labourPayment->fresh()));
    }

    public function markPaid(Request $request, Project $project, LabourPayment $labourPayment): LabourPaymentResource
    {
        abort_unless((int) $labourPayment->project_id === (int) $project->id, 404);
        Gate::authorize('markPaid', $labourPayment);
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->payments->markPaid($labourPayment, $request->user(), $data);

        return new LabourPaymentResource($this->detail($labourPayment->fresh()));
    }

    private function detail(LabourPayment $payment): LabourPayment
    {
        return $payment->load([
            'lines.labour.trade:id,name',
        ])->loadCount('lines');
    }
}
