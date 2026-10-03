<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\VendorQuotation;
use App\Models\Procurement\VendorQuotationItem;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Procurement\VendorQuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class VendorQuotationController extends Controller
{
    public function __construct(private readonly VendorQuotationService $quotations) {}

    public function create(Request $request, Project $project, Rfq $rfq): Response
    {
        Gate::authorize('create', [VendorQuotation::class, $rfq]);

        $quoted = $rfq->quotations()->pluck('vendor_id')->all();
        $vendors = $rfq->vendors()->with('vendor:id,code,name,state_code')->get()
            ->reject(fn ($v) => in_array($v->vendor_id, $quoted, true))
            ->map(fn ($v) => ['value' => $v->vendor_id, 'label' => $v->vendor?->name, 'description' => $v->vendor?->code])->values()->all();

        return $this->form($project, $rfq, null, $vendors, $request->integer('vendor') ?: null);
    }

    public function store(Request $request, Project $project, Rfq $rfq): RedirectResponse
    {
        Gate::authorize('create', [VendorQuotation::class, $rfq]);

        $quotation = $this->quotations->save($rfq, $this->validated($request, true));

        return redirect()->route('projects.rfqs.show', [$project, $rfq])->with('success', 'Quotation recorded for '.$quotation->vendor()->value('name').'.');
    }

    public function edit(Project $project, Rfq $rfq, VendorQuotation $quotation): Response
    {
        Gate::authorize('update', $quotation);
        abort_unless($quotation->isEditable(), 403);

        return $this->form($project, $rfq, $quotation, [], null);
    }

    public function update(Request $request, Project $project, Rfq $rfq, VendorQuotation $quotation): RedirectResponse
    {
        Gate::authorize('update', $quotation);

        $this->quotations->save($rfq, $this->validated($request, false), $quotation);

        return redirect()->route('projects.rfqs.show', [$project, $rfq])->with('success', 'Quotation updated.');
    }

    public function destroy(Project $project, Rfq $rfq, VendorQuotation $quotation): RedirectResponse
    {
        Gate::authorize('delete', $quotation);

        $this->quotations->delete($quotation);

        return redirect()->route('projects.rfqs.show', [$project, $rfq])->with('success', 'Quotation removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'vendor_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'quotation_number' => ['nullable', 'string', 'max:50'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'freight_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.rfq_item_id' => ['required', 'integer', 'distinct'],
            'items.*.rate' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'items.*.tax_rate_id' => ['nullable', ExistsInCompany::active(TaxRate::class)],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ], [], ['items.*.rate' => 'rate', 'items.*.discount_percent' => 'discount', 'items.*.tax_rate_id' => 'tax rate']);
    }

    /**
     * @param  list<array<string, mixed>>  $vendors
     */
    private function form(Project $project, Rfq $rfq, ?VendorQuotation $quotation, array $vendors, ?int $vendorId): Response
    {
        $lines = $quotation ? $quotation->items()->get()->keyBy('rfq_item_id') : collect();
        $items = $rfq->items()->with(['material:id,code,name,tax_rate_id', 'unit:id,symbol'])->get();

        return Inertia::render('Procurement/Rfqs/Quotation', [
            'project' => ProjectHeader::for($project),
            'rfq' => ['id' => $rfq->id, 'rfq_number' => $rfq->rfq_number, 'title' => $rfq->title, 'status' => $rfq->status->value],
            'quotation' => $quotation ? [
                'id' => $quotation->id,
                'vendor' => ProcurementPresenter::vendor($quotation->vendor()->first()),
                ...$quotation->only(['quotation_number', 'delivery_days', 'payment_terms', 'warranty', 'freight_amount', 'other_charges', 'remarks', 'subtotal', 'discount_amount', 'taxable_amount', 'tax_amount', 'grand_total']),
                'quotation_date' => $quotation->quotation_date?->toDateString(),
                'valid_until' => $quotation->valid_until?->toDateString(),
            ] : null,
            'items' => $items->map(function (RfqItem $i) use ($lines) {
                /** @var VendorQuotationItem|null $line */
                $line = $lines->get($i->id);

                return [
                    'rfq_item_id' => $i->id,
                    'material' => $i->material?->only(['id', 'code', 'name']),
                    'unit' => $i->unit?->symbol,
                    'quantity' => $i->quantity,
                    'specification' => $i->specification,
                    'rate' => $line?->rate,
                    'discount_percent' => $line?->discount_percent ?? '0',
                    'tax_rate_id' => $line ? $line->tax_rate_id : $i->material?->tax_rate_id,
                    'remarks' => $line?->remarks,
                ];
            })->all(),
            'vendors' => $vendors,
            'preselectVendor' => $vendorId,
            'taxRates' => ProcurementPresenter::taxRateOptions(),
            'today' => now()->toDateString(),
            'can' => ['delete' => $quotation !== null && Gate::allows('delete', $quotation)],
        ]);
    }
}
