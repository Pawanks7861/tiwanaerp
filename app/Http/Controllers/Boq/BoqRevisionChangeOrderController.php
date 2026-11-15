<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\RevisionChangeOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Projects\Project;
use App\Services\Boq\AdvancedBoqRevisionComparisonService;
use App\Services\Boq\BoqRevisionChangeOrderService;
use App\Support\Boq\BoqRevisionPairResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BoqRevisionChangeOrderController extends Controller
{
    public function __construct(
        private readonly BoqRevisionChangeOrderService $orders,
        private readonly BoqRevisionPairResolver $pairs,
        private readonly AdvancedBoqRevisionComparisonService $comparison,
    ) {}

    public function index(Request $request, Project $project, Boq $boq): Response
    {
        Gate::authorize('view', $boq);

        $user = $request->user();
        $rows = BoqRevisionChangeOrder::query()
            ->with(['fromBoq:id,version', 'toBoq:id,version', 'requestedBy:id,name', 'approvedBy:id,name'])
            ->where('project_id', $project->id)
            ->where('boq_number', $boq->boq_number)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Boq/ChangeOrders/Index', [
            'project' => ProjectHeader::for($project),
            'boq' => BoqPresenter::header($boq, $user->can('boq.view_costs')),
            'change_orders' => $rows->map(fn (BoqRevisionChangeOrder $co) => $this->listRow($co))->all(),
            'can' => [
                'create' => $user->can('create', [BoqRevisionChangeOrder::class, $project]),
                'view_costs' => $user->can('boq.view_costs'),
            ],
        ]);
    }

    public function store(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('create', [BoqRevisionChangeOrder::class, $project]);

        [$from, $to] = $this->pairs->resolve($project, $boq, $request);
        Gate::authorize('view', $from);
        Gate::authorize('view', $to);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $order = $this->orders->createDraft(
            $project,
            $from,
            $to,
            $request->user(),
            $data['title'] ?? null,
            $data['description'] ?? null,
        );

        return redirect()
            ->route('projects.boqs.change-orders.show', [$project, $boq, $order])
            ->with('success', "Change order {$order->change_order_number} saved as draft.");
    }

    public function show(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): Response
    {
        abort_unless((int) $changeOrder->project_id === (int) $project->id, 404);
        abort_unless((string) $changeOrder->boq_number === (string) $boq->boq_number, 404);
        Gate::authorize('view', $changeOrder);

        $user = $request->user();
        $withCosts = $user->can('boq.view_costs');
        $changeOrder->load(['fromBoq:id,version,boq_number,title,status', 'toBoq:id,version,boq_number,title,status', 'requestedBy:id,name', 'submittedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name']);

        if ($changeOrder->status === RevisionChangeOrderStatus::Approved && is_array($changeOrder->comparison_snapshot)) {
            $snapshot = $withCosts
                ? $changeOrder->comparison_snapshot
                : $this->comparison->compare($changeOrder->fromBoq, $changeOrder->toBoq, false);
        } else {
            $snapshot = $this->comparison->compare($changeOrder->fromBoq, $changeOrder->toBoq, $withCosts);
        }

        return Inertia::render('Boq/ChangeOrders/Show', [
            'project' => ProjectHeader::for($project),
            'boq' => BoqPresenter::header($boq, $withCosts),
            'change_order' => $this->detailRow($changeOrder),
            'comparison' => $snapshot,
            'can' => [
                'submit' => $user->can('submit', $changeOrder),
                'approve' => $user->can('approve', $changeOrder),
                'reject' => $user->can('reject', $changeOrder),
                'cancel' => $user->can('cancel', $changeOrder),
                'view_costs' => $withCosts,
                'internal_pdf' => $user->can('downloadInternalPdf', $changeOrder),
                'client_pdf' => $user->can('downloadClientPdf', $changeOrder),
            ],
        ]);
    }

    public function submit(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): RedirectResponse
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('submit', $changeOrder);
        $this->orders->submit($changeOrder, $request->user());

        return back()->with('success', 'Change order submitted for approval.');
    }

    public function approve(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): RedirectResponse
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('approve', $changeOrder);
        $this->orders->approve($changeOrder, $request->user());

        return back()->with('success', 'Change order approved.');
    }

    public function reject(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): RedirectResponse
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('reject', $changeOrder);

        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->orders->reject($changeOrder, $request->user(), $data['rejection_reason']);

        return back()->with('success', 'Change order rejected.');
    }

    public function cancel(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): RedirectResponse
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('cancel', $changeOrder);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->orders->cancel($changeOrder, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Change order cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(BoqRevisionChangeOrder $co): array
    {
        return [
            'id' => $co->id,
            'change_order_number' => $co->change_order_number,
            'title' => $co->title,
            'status' => $co->status->value,
            'status_label' => $co->status->label(),
            'from_version' => $co->fromBoq?->version,
            'to_version' => $co->toBoq?->version,
            'commercial_variation' => (string) $co->commercial_variation,
            'internal_cost_variation' => (string) $co->internal_cost_variation,
            'submitted_at' => $co->submitted_at?->toIso8601String(),
            'approved_at' => $co->approved_at?->toIso8601String(),
            'requested_by' => $co->requestedBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRow(BoqRevisionChangeOrder $co): array
    {
        return [
            ...$this->listRow($co),
            'description' => $co->description,
            'revision_reason' => $co->revision_reason,
            'margin_variation' => (string) $co->margin_variation,
            'from_boq_id' => $co->from_boq_id,
            'to_boq_id' => $co->to_boq_id,
            'rejection_reason' => $co->rejection_reason,
            'submitted_by' => $co->submittedBy?->name,
            'approved_by' => $co->approvedBy?->name,
            'rejected_by' => $co->rejectedBy?->name,
            'rejected_at' => $co->rejected_at?->toIso8601String(),
        ];
    }

    private function assertOrder(Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): void
    {
        abort_unless((int) $changeOrder->project_id === (int) $project->id, 404);
        abort_unless((string) $changeOrder->boq_number === (string) $boq->boq_number, 404);
    }
}
