<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\RfqStatus;
use App\Enums\Procurement\SelectionBasis;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\VendorQuotation;
use App\Models\Procurement\VendorQuotationItem;
use App\Models\Projects\Project;
use App\Services\Procurement\BidComparisonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RFQ → Comparison: matrix of RFQ items (rows) × vendors (columns). Differences are highlighted in
 * the UI; the user selects the vendor and records the basis and justification.
 */
class BidComparisonController extends Controller
{
    public function __construct(private readonly BidComparisonService $comparisons) {}

    public function show(Request $request, Project $project, Rfq $rfq): Response
    {
        Gate::authorize('view', [BidComparison::class, $rfq]);

        $user = $request->user();
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->with(['submitter:id,name', 'approver:id,name'])->first();
        $items = $rfq->items()->with(['material:id,code,name', 'unit:id,symbol'])->get();
        $quotations = $user->can('vendor_quotations.view')
            ? $rfq->quotations()->with(['vendor:id,code,name,state_code,gstin', 'items'])->get()
            : collect();

        $editable = $rfq->status === RfqStatus::QuotesReceived && ($comparison === null
            ? $user->can('create', [BidComparison::class, $rfq])
            : $comparison->isEditable() && $user->can('update', $comparison));

        return Inertia::render('Procurement/Rfqs/Comparison', [
            'project' => ProjectHeader::for($project),
            'rfq' => ['id' => $rfq->id, 'rfq_number' => $rfq->rfq_number, 'title' => $rfq->title, 'status' => $rfq->status->value, 'status_label' => $rfq->status->label()],
            'items' => $items->map(fn (RfqItem $i) => [
                'id' => $i->id,
                'material' => $i->material?->only(['id', 'code', 'name']),
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
            ])->all(),
            'quotations' => $quotations->map(fn (VendorQuotation $q) => [
                'id' => $q->id,
                'vendor' => $q->vendor?->only(['id', 'code', 'name', 'state_code', 'gstin']),
                ...$q->only(['quotation_number', 'delivery_days', 'payment_terms', 'warranty', 'subtotal', 'discount_amount', 'taxable_amount', 'tax_amount', 'freight_amount', 'other_charges', 'grand_total', 'is_selected']),
                'quotation_date' => $q->quotation_date?->toDateString(),
                'valid_until' => $q->valid_until?->toDateString(),
                'lines' => $q->items->mapWithKeys(fn (VendorQuotationItem $l) => [$l->rfq_item_id => $l->only([
                    'rate', 'discount_percent', 'discount_amount', 'taxable_amount', 'tax_percent', 'tax_amount', 'amount', 'remarks',
                ])])->all(),
            ])->values()->all(),
            'comparison' => $comparison ? [
                'id' => $comparison->id,
                'status' => $comparison->status->value,
                'status_label' => $comparison->status->label(),
                'selected_vendor_quotation_id' => $comparison->selected_vendor_quotation_id,
                'selection_basis' => $comparison->selection_basis?->value,
                'justification' => $comparison->justification,
                'submitted_by' => $comparison->submitter?->name,
                'submitted_at' => $comparison->submitted_at?->toIso8601String(),
                'approved_by' => $comparison->approver?->name,
                'approved_at' => $comparison->approved_at?->toIso8601String(),
                'rejection_reason' => $comparison->rejection_reason,
            ] : null,
            'bases' => SelectionBasis::options(),
            'can' => [
                'edit' => $editable,
                'submit' => $editable && $comparison !== null,
                'approve' => $comparison?->status === BidComparisonStatus::Submitted && $user->can('approve', $comparison)
                    && (config('approvals.allow_self_approval') || (int) $comparison->submitted_by !== (int) $user->id),
            ],
        ]);
    }

    public function save(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->first();
        $comparison === null
            ? Gate::authorize('create', [BidComparison::class, $rfq])
            : Gate::authorize('update', $comparison);

        $data = $request->validate([
            'selected_vendor_quotation_id' => ['nullable', 'integer'],
            'selection_basis' => ['nullable', Rule::enum(SelectionBasis::class)],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);

        $comparison ??= $this->comparisons->forRfq($rfq);
        $this->comparisons->save($comparison, $data);

        return back()->with('success', 'Comparison saved.');
    }

    public function submit(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->firstOrFail();
        Gate::authorize('update', $comparison);

        $this->comparisons->submit($comparison, $request->user());

        return back()->with('success', 'Comparison submitted for approval.');
    }

    public function approve(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->firstOrFail();
        Gate::authorize('approve', $comparison);

        $this->comparisons->approve($comparison, $request->user());

        return back()->with('success', 'Comparison approved. A purchase order can now be created.');
    }

    public function reject(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->firstOrFail();
        Gate::authorize('approve', $comparison);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
        $this->comparisons->reject($comparison, $request->user(), $reason);

        return back()->with('success', 'Comparison rejected.');
    }
}
