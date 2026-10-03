<?php

namespace Tests\Concerns;

use App\Enums\ProjectRole;
use App\Models\Boq\Boq;
use App\Models\Masters\Material;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\VendorQuotation;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Procurement\BidComparisonService;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialRequestService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\RfqService;
use App\Services\Procurement\VendorQuotationService;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;

/**
 * Phase 3 fixtures on top of the Phase 2 project team: a purchase manager and a store manager on
 * the project, an approved BOQ, two GST-rated materials and vendors in the company's state (27,
 * intra-state) and in Karnataka (29, inter-state). Workflows: MR = PM → Purchase Manager,
 * PO = PM → Director, GRN = PM; bid comparison approval needs bid_comparison.approve (Director).
 */
trait BuildsProcurementData
{
    use BuildsProjectPlanningData;

    public User $purchaser;

    public User $storekeeper;

    public Boq $boq;

    public Material $cement;

    public Material $steel;

    public Vendor $vendorIntra;

    public Vendor $vendorInter;

    public Vendor $vendorThird;

    public ?Warehouse $siteStoreModel = null;

    public function setUpProcurement(): void
    {
        $this->setUpProjectTeam();
        $this->purchaser = $this->createMember($this->company, DefaultRoles::PURCHASE_MANAGER);
        $this->storekeeper = $this->createMember($this->company, DefaultRoles::STORE_MANAGER);
        $this->inCompany($this->company, function () {
            $projects = app(ProjectService::class);
            $projects->assignMember($this->project, $this->purchaser->id, ProjectRole::Purchase);
            $projects->assignMember($this->project, $this->storekeeper->id, ProjectRole::Store);
        });

        $this->boq = $this->approveBoq($this->makeBoq());
        $bag = $this->unitId('Bag');
        $mt = $this->unitId('MT');

        $this->inCompany($this->company, function () use ($bag, $mt) {
            $gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
            $this->cement = Material::query()->create(['code' => 'CEM53', 'name' => 'Cement OPC 53', 'unit_id' => $bag, 'tax_rate_id' => $gst18, 'hsn_sac' => '2523']);
            $this->steel = Material::query()->create(['code' => 'TMT12', 'name' => 'TMT bar 12 mm', 'unit_id' => $mt, 'tax_rate_id' => $gst18, 'hsn_sac' => '7214']);
            $this->vendorIntra = Vendor::query()->create([
                'code' => 'V001', 'name' => 'Mumbai Traders', 'state_code' => '27', 'gstin' => '27AAAAA0000A1Z5',
                'payment_terms' => '30 days', 'bank_name' => 'HDFC', 'bank_account_no' => '50100123456789', 'bank_ifsc' => 'HDFC0000001',
            ]);
            $this->vendorInter = Vendor::query()->create(['code' => 'V002', 'name' => 'Bengaluru Steel', 'state_code' => '29', 'gstin' => '29AAAAA0000A1Z5']);
            $this->vendorThird = Vendor::query()->create(['code' => 'V003', 'name' => 'Pune Supplies', 'state_code' => '27']);
        });
    }

    /**
     * The project's site store (created on first use); GRNs are received into it by default.
     */
    public function siteStore(): Warehouse
    {
        return $this->siteStoreModel ??= $this->inCompany($this->company, fn () => Warehouse::query()->create([
            'project_id' => $this->project->id, 'code' => 'WH-SITE', 'name' => 'Tower A site store', 'type' => 'site',
        ]));
    }

    public function boqItemId(int $index = 0): int
    {
        return $this->inCompany($this->company, fn () => (int) $this->boq->items()->orderBy('sort_order')->skip($index)->value('id'));
    }

    /**
     * Cement 100 Bag (linked to BOQ line 1) and steel 5 MT.
     *
     * @return array<string, mixed>
     */
    public function mrPayload(array $overrides = []): array
    {
        return array_replace([
            'request_date' => now()->toDateString(),
            'required_date' => now()->addDays(10)->toDateString(),
            'priority' => 'high',
            'remarks' => 'For raft foundation',
            'items' => [
                ['material_id' => $this->cement->id, 'unit_id' => $this->cement->unit_id, 'quantity' => '100', 'boq_item_id' => $this->boqItemId()],
                ['material_id' => $this->steel->id, 'unit_id' => $this->steel->unit_id, 'quantity' => '5'],
            ],
        ], $overrides);
    }

    public function makeMr(array $overrides = []): MaterialRequest
    {
        return $this->inCompany($this->company, fn () => app(MaterialRequestService::class)->create($this->project, $this->mrPayload($overrides), $this->engineer));
    }

    /**
     * Engineer submits → PM approves → Purchase Manager approves.
     */
    public function approvedMr(array $overrides = []): MaterialRequest
    {
        $mr = $this->makeMr($overrides);

        return $this->inCompany($this->company, function () use ($mr) {
            app(MaterialRequestService::class)->submit($mr, $this->engineer);
            $approvals = app(ApprovalService::class);
            $request = $approvals->approve($mr->fresh()->pendingApprovalRequest(), $this->pm);
            $approvals->approve($request, $this->purchaser);

            return $mr->fresh();
        });
    }

    /**
     * RFQ for every MR line at its full quantity, sent to the given vendors.
     *
     * @param  list<Vendor>|null  $vendors
     */
    public function sentRfq(MaterialRequest $mr, ?array $vendors = null, bool $send = true): Rfq
    {
        $vendors ??= [$this->vendorIntra, $this->vendorInter];

        return $this->inCompany($this->company, function () use ($mr, $vendors, $send) {
            $service = app(RfqService::class);
            $rfq = $service->create($this->project, [
                'title' => 'Cement and steel',
                'rfq_date' => now()->toDateString(),
                'due_date' => now()->addDays(3)->toDateString(),
                'items' => $mr->items()->get()->map(fn ($i) => ['material_request_item_id' => $i->id, 'quantity' => $i->quantity])->all(),
                'vendor_ids' => array_map(fn (Vendor $v) => $v->id, $vendors),
            ]);
            if ($send) {
                $service->send($rfq);
            }

            return $rfq->fresh();
        });
    }

    /**
     * @param  list<string|null>  $rates  one rate per RFQ item (null = not quoted)
     */
    public function quote(Rfq $rfq, Vendor $vendor, array $rates, array $extra = []): VendorQuotation
    {
        return $this->inCompany($this->company, function () use ($rfq, $vendor, $rates, $extra) {
            $gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
            $items = $rfq->items()->orderBy('sort_order')->get()->values()->map(fn ($item, $i) => [
                'rfq_item_id' => $item->id, 'rate' => $rates[$i] ?? null, 'discount_percent' => '0', 'tax_rate_id' => $gst18,
            ])->all();

            return app(VendorQuotationService::class)->save($rfq->fresh(), [
                'vendor_id' => $vendor->id,
                'quotation_date' => now()->toDateString(),
                'delivery_days' => 7,
                'items' => $items,
            ] + $extra);
        });
    }

    /**
     * Purchase manager selects the quotation and submits; the director approves.
     */
    public function approvedComparison(Rfq $rfq, VendorQuotation $quotation): BidComparison
    {
        return $this->inCompany($this->company, function () use ($rfq, $quotation) {
            $service = app(BidComparisonService::class);
            $comparison = $service->forRfq($rfq->fresh());
            $service->save($comparison, ['selected_vendor_quotation_id' => $quotation->id, 'selection_basis' => 'lowest_price', 'justification' => 'Lowest landed cost for all items.']);
            $service->submit($comparison->fresh(), $this->purchaser);
            $service->approve($comparison->fresh(), $this->director);

            return $comparison->fresh();
        });
    }

    public function poFromRfq(Rfq $rfq): PurchaseOrder
    {
        return $this->inCompany($this->company, fn () => app(PurchaseOrderService::class)->createFromComparison($rfq->fresh()));
    }

    /**
     * Purchase manager submits → PM → Director.
     */
    public function approvePo(PurchaseOrder $po): PurchaseOrder
    {
        return $this->inCompany($this->company, function () use ($po) {
            app(PurchaseOrderService::class)->submit($po->fresh(), $this->purchaser);
            $approvals = app(ApprovalService::class);
            $request = $approvals->approve($po->fresh()->pendingApprovalRequest(), $this->pm);
            $approvals->approve($request, $this->director);

            return $po->fresh();
        });
    }

    /**
     * Full chain up to an approved PO for the intra-state vendor (cement 100 @ 400, steel 5 @ 62,000).
     */
    public function approvedPo(?Vendor $vendor = null): PurchaseOrder
    {
        $vendor ??= $this->vendorIntra;
        $rfq = $this->sentRfq($this->approvedMr(), [$vendor, $vendor->is($this->vendorIntra) ? $this->vendorInter : $this->vendorIntra]);
        $quotation = $this->quote($rfq, $vendor, ['400', '62000']);
        $this->approvedComparison($rfq, $quotation);

        return $this->approvePo($this->poFromRfq($rfq));
    }

    /**
     * @param  array<int, array{0: string, 1?: string, 2?: string}>  $quantities  by PO line index: [received, rejected, reason]
     */
    public function makeGrn(PurchaseOrder $po, array $quantities, ?Warehouse $warehouse = null): Grn
    {
        return $this->inCompany($this->company, function () use ($po, $quantities, $warehouse) {
            $lines = $po->items()->orderBy('sort_order')->get()->values();
            $items = [];
            foreach ($quantities as $index => $q) {
                $items[] = [
                    'purchase_order_item_id' => $lines[$index]->id,
                    'received_qty' => $q[0],
                    'rejected_qty' => $q[1] ?? '0',
                    'rejection_reason' => $q[2] ?? null,
                ];
            }

            return app(GrnService::class)->create($po->fresh(), [
                'warehouse_id' => ($warehouse ?? $this->siteStore())->id,
                'receipt_date' => now()->toDateString(),
                'vendor_invoice_no' => 'INV-'.random_int(100, 999),
                'items' => $items,
            ]);
        });
    }

    /**
     * Store manager submits → PM approves.
     */
    public function approveGrn(Grn $grn): Grn
    {
        return $this->inCompany($this->company, function () use ($grn) {
            app(GrnService::class)->submit($grn->fresh(), $this->storekeeper);
            app(ApprovalService::class)->approve($grn->fresh()->pendingApprovalRequest(), $this->pm);

            return $grn->fresh();
        });
    }
}
