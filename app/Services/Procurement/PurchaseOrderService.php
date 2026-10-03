<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\RfqStatus;
use App\Events\Procurement\PurchaseOrderApproved;
use App\Models\Core\Company;
use App\Models\Masters\Material;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vendor;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\VendorQuotation;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Tax\GstCalculator;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProcurementQuantityService $quantities,
        private readonly GstCalculator $gst,
        private readonly RfqService $rfqs,
    ) {}

    /**
     * Draft PO populated from the approved comparison's selected quotation. Quantities are capped
     * at what is still unordered on the material request lines; the RFQ is closed.
     */
    public function createFromComparison(Rfq $rfq): PurchaseOrder
    {
        return DB::transaction(function () use ($rfq) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            $comparison = BidComparison::query()->where('rfq_id', $rfq->id)->first();

            if ($comparison?->status !== BidComparisonStatus::Approved || ! in_array($rfq->status, [RfqStatus::Evaluated, RfqStatus::Closed], true)) {
                throw ValidationException::withMessages(['rfq' => 'A purchase order can only be created from an approved bid comparison.']);
            }
            if ($rfq->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Cancelled->value)->exists()) {
                throw ValidationException::withMessages(['rfq' => 'A purchase order already exists for this RFQ.']);
            }

            $quotation = VendorQuotation::query()->whereKey($comparison->selected_vendor_quotation_id)->where('rfq_id', $rfq->id)
                ->with('items.rfqItem')->firstOrFail();
            $vendor = $this->activeVendor($quotation->vendor_id);
            $project = Project::query()->findOrFail($rfq->project_id);

            $mrItems = MaterialRequestItem::query()->whereKey($quotation->items->pluck('rfqItem.material_request_item_id')->filter())->get();
            $remaining = $this->quantities->remaining($mrItems, exceptRfqId: $rfq->id);
            $materials = Material::query()->whereKey($quotation->items->pluck('rfqItem.material_id'))->get()->keyBy('id');

            $rows = [];
            foreach ($quotation->items as $line) {
                $qty = Decimal::of($line->quantity);
                $mrItemId = $line->rfqItem->material_request_item_id;
                if ($mrItemId !== null && $qty->greaterThan($remaining[$mrItemId])) {
                    $qty = Decimal::of($remaining[$mrItemId]);
                }
                if (! $qty->isPositive()) {
                    continue;
                }

                $material = $materials->get($line->rfqItem->material_id);
                $rows[] = [
                    'material_request_item_id' => $mrItemId,
                    'vendor_quotation_item_id' => $line->id,
                    'material_id' => $material->id,
                    'unit_id' => $line->rfqItem->unit_id,
                    'item_code' => $material->code,
                    'description' => $material->name,
                    'hsn_sac' => $material->hsn_sac,
                    'quantity' => $qty->toQuantity(),
                    'rate' => $line->rate,
                    'discount_percent' => $line->discount_percent,
                    'tax_rate_id' => $line->tax_rate_id,
                ];
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['rfq' => 'Everything on this RFQ has already been ordered.']);
            }

            $po = $this->newOrder($project, $vendor, [
                'po_date' => now()->toDateString(),
                'delivery_date' => $quotation->delivery_days !== null ? now()->addDays($quotation->delivery_days)->toDateString() : $rfq->required_date?->toDateString(),
                'payment_terms' => $quotation->payment_terms ?? $vendor->payment_terms,
                'terms' => $rfq->terms,
                'freight_amount' => $quotation->freight_amount,
                'other_charges' => $quotation->other_charges,
            ]);
            $po->forceFill(['rfq_id' => $rfq->id, 'vendor_quotation_id' => $quotation->id])->save();

            // Close first: an open RFQ still reserves its quantities against the MR lines.
            $rfq->forceFill(['status' => RfqStatus::Closed, 'closed_at' => now()])->save();
            $this->writeLines($po, $rows, $vendor);

            return $po;
        });
    }

    /**
     * Direct PO (no RFQ) for approved material request lines; requires a justification.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDirect(Project $project, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($project, $data) {
            $vendor = $this->activeVendor((int) $data['vendor_id']);
            $po = $this->newOrder($project, $vendor, $this->header($data));
            $po->forceFill(['direct_justification' => $data['direct_justification']])->save();
            $this->writeLines($po, $data['items'], $vendor);

            return $po;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $po->assertEditable();
            $po->fill($this->header($data));
            if ($po->isDirect() && array_key_exists('direct_justification', $data)) {
                $po->forceFill(['direct_justification' => $data['direct_justification']]);
            }
            $po->save();

            $this->writeLines($po, $data['items'], Vendor::query()->findOrFail($po->vendor_id));

            return $po;
        });
    }

    public function delete(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $po->assertEditable();
            if ($po->revision_no > 0) {
                throw ValidationException::withMessages(['purchase_order' => 'An amended purchase order cannot be deleted. Cancel it instead.']);
            }

            $po->delete();
            $this->reopenRfq($po);
        });
    }

    public function submit(PurchaseOrder $po, User $user): void
    {
        DB::transaction(function () use ($po, $user) {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'Add at least one line before submitting.']);
            }

            $this->recalculate($locked);
            $this->approvals->submit($locked, $user);
            $po->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the approval engine's transaction). Re-checks over-procurement,
     * recomputes MR ordered quantities and the receipt status. Repeating it changes nothing.
     */
    public function markApproved(PurchaseOrder $po, ?int $approverId): void
    {
        DB::transaction(function () use ($po, $approverId) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status->isApprovedOrder()) {
                return;
            }

            $this->assertWithinRemaining($po, $po->items()->get());

            $po->forceFill(['status' => PurchaseOrderStatus::Approved, 'approved_by' => $approverId, 'approved_at' => now()])->save();
            $po->writeAudit('approved', null, ['revision_no' => $po->revision_no, 'grand_total' => $po->grand_total]);

            $this->quantities->refreshPurchaseOrder($po);
            PurchaseOrderApproved::dispatch($po->fresh());
        });
    }

    /**
     * Cancel an order nothing was received against. Draft amendments, approved and rejected orders
     * can be cancelled; submitted ones must be withdrawn from approval first.
     */
    public function cancel(PurchaseOrder $po, string $reason, User $user): void
    {
        DB::transaction(function () use ($po, $reason, $user) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            $cancellable = in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Rejected], true)
                || ($po->status === PurchaseOrderStatus::Draft && $po->revision_no > 0);
            if (! $cancellable) {
                throw ValidationException::withMessages(['purchase_order' => $po->status === PurchaseOrderStatus::Draft
                    ? 'Delete the draft purchase order instead.'
                    : 'This purchase order cannot be cancelled in its current status.']);
            }

            $this->assertNoPendingGrns($po);
            if ($po->grns()->where('status', GrnStatus::Approved->value)->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'Goods were already received against this order. Close it instead of cancelling.']);
            }

            $po->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ])->save();

            $this->quantities->refreshMaterialRequests($po->items()->pluck('material_request_item_id')->filter()->all());
        });
    }

    /**
     * Short-close a partially received order: the unreceived balance is no longer on order.
     */
    public function close(PurchaseOrder $po, string $reason, User $user): void
    {
        DB::transaction(function () use ($po, $reason, $user) {
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only received or partially received orders can be closed.']);
            }
            $this->assertNoPendingGrns($po);

            $po->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'cancelled_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ])->save();
            $po->writeAudit('closed', null, ['reason' => $reason]);

            $this->quantities->refreshMaterialRequests($po->items()->pluck('material_request_item_id')->filter()->all());
        });
    }

    /**
     * Recompute every line and the totals from the stored inputs (vendor state may have changed).
     */
    public function recalculate(PurchaseOrder $po): void
    {
        $rows = $po->items()->get()->map(fn (PurchaseOrderItem $item) => [
            'id' => $item->id,
            ...$item->only(['quantity', 'rate', 'discount_percent', 'tax_rate_id', 'description', 'hsn_sac']),
        ])->all();

        $this->writeLines($po, $rows, Vendor::query()->findOrFail($po->vendor_id));
    }

    public function assertNoPendingGrns(PurchaseOrder $po): void
    {
        if ($po->grns()->whereIn('status', [GrnStatus::Draft->value, GrnStatus::Submitted->value, GrnStatus::Rejected->value])->exists()) {
            throw ValidationException::withMessages(['purchase_order' => 'Finish or delete the open GRNs of this order first.']);
        }
    }

    /**
     * Writes the lines with server-side GST and the document totals.
     * Rows with an id update that line; new rows must reference an approved MR line of the project.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeLines(PurchaseOrder $po, array $rows, Vendor $vendor): void
    {
        $taxType = $this->gst->taxType($vendor->state_code, $po->place_of_supply_state);
        $existing = $po->items()->get()->keyBy('id');

        $newMrIds = array_map('intval', array_filter(array_map(fn ($r) => empty($r['id']) ? ($r['material_request_item_id'] ?? null) : null, $rows)));
        $procurable = $this->rfqs->procurableItems($po->project_id, $newMrIds);
        $materials = Material::query()->whereKey($procurable->pluck('material_id'))->get()->keyBy('id');
        $taxIds = array_filter(array_map(fn ($r) => $r['tax_rate_id'] ?? null, $rows));
        $taxRates = TaxRate::query()->whereKey($taxIds)->get()->keyBy('id');

        $kept = [];
        $amounts = [];
        foreach (array_values($rows) as $index => $row) {
            if (! empty($row['id'])) {
                $line = $existing->get((int) $row['id'])
                    ?? throw ValidationException::withMessages(["items.{$index}.id" => 'This line does not belong to the purchase order.']);
            } else {
                $line = new PurchaseOrderItem;
                $line->forceFill(array_intersect_key($row, array_flip(['vendor_quotation_item_id', 'material_id', 'unit_id', 'item_code', 'description', 'hsn_sac'])));
                $mrItem = $procurable->get((int) ($row['material_request_item_id'] ?? 0));
                if (! isset($row['vendor_quotation_item_id'])) {
                    $mrItem ?? throw ValidationException::withMessages(["items.{$index}.material_request_item_id" => 'Select an approved material request line of this project.']);
                    $material = $materials->get($mrItem->material_id);
                    $line->forceFill([
                        'material_id' => $mrItem->material_id,
                        'unit_id' => $mrItem->unit_id,
                        'item_code' => $material?->code,
                        'description' => $material?->name ?? 'Item',
                        'hsn_sac' => $material?->hsn_sac,
                    ]);
                }
                $line->forceFill(['purchase_order_id' => $po->id, 'material_request_item_id' => $row['material_request_item_id'] ?? null]);
            }

            $taxRateId = $row['tax_rate_id'] ?? null;
            $tax = $taxRateId ? $taxRates->get((int) $taxRateId) : null;
            if ($taxRateId && ($tax === null || (! $tax->is_active && (int) $line->tax_rate_id !== (int) $taxRateId))) {
                throw ValidationException::withMessages(["items.{$index}.tax_rate_id" => 'Select an active tax rate.']);
            }

            $qty = Decimal::of((string) $row['quantity']);
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'The quantity must be greater than zero.']);
            }
            if ($line->exists && $qty->lessThan($line->received_qty)) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => "The quantity cannot be less than the {$line->received_qty} already received."]);
            }

            $rate = Decimal::of((string) $row['rate'])->toRate();
            $discount = Decimal::of((string) ($row['discount_percent'] ?? '0'))->round(Decimal::PERCENT_SCALE)->toString();
            $calc = $this->gst->line($qty->toQuantity(), $rate, $discount, $tax, $taxType);

            $line->setRelation('purchaseOrder', $po);
            $line->forceFill([
                'description' => filled($row['description'] ?? null) ? $row['description'] : $line->description,
                'hsn_sac' => array_key_exists('hsn_sac', $row) ? ($row['hsn_sac'] ?: null) : $line->hsn_sac,
                'quantity' => $qty->toQuantity(),
                'rate' => $rate,
                'discount_percent' => $discount,
                'tax_rate_id' => $tax?->id,
                'sort_order' => $index + 1,
                ...$calc,
            ]);
            if ($line->isDirty()) {
                $line->save();
            }
            $kept[] = $line->id;
            $amounts[] = $calc;
        }

        if ($amounts === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        $existing->except($kept)->each(function (PurchaseOrderItem $line) use ($po) {
            if ($line->grnItems()->exists()) {
                throw ValidationException::withMessages(['items' => "{$line->description} has goods receipts and cannot be removed."]);
            }
            $line->setRelation('purchaseOrder', $po);
            $line->delete();
        });

        $this->assertWithinRemaining($po, $po->items()->get());

        $po->forceFill([
            'vendor_state_code' => $vendor->state_code,
            'tax_type' => $taxType,
            ...$this->gst->orderTotals($amounts, $po->freight_amount, $po->other_charges),
        ])->save();
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     */
    private function assertWithinRemaining(PurchaseOrder $po, Collection $items): void
    {
        $mrItems = MaterialRequestItem::query()->whereKey($items->pluck('material_request_item_id')->filter()->unique())->get();
        $remaining = $this->quantities->remaining($mrItems, exceptOrderId: $po->id);
        $onOrder = [];

        foreach ($items as $item) {
            if ($item->material_request_item_id === null) {
                continue;
            }
            $id = $item->material_request_item_id;
            $onOrder[$id] = ($onOrder[$id] ?? Decimal::zero())->plus($item->quantity);
            if ($onOrder[$id]->greaterThan($remaining[$id])) {
                throw ValidationException::withMessages([
                    'items' => "{$item->description}: only {$remaining[$id]} remain to be ordered on the material request line.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function newOrder(Project $project, Vendor $vendor, array $header): PurchaseOrder
    {
        $company = Company::query()->findOrFail($project->company_id);

        $po = new PurchaseOrder([
            'billing_address' => $this->companyAddress($company),
            'shipping_address' => $this->projectAddress($project),
            'place_of_supply_state' => $project->state_code ?? $company->state_code,
            'payment_terms' => $vendor->payment_terms,
            'freight_amount' => '0.00',
            'other_charges' => '0.00',
            ...array_filter($header, fn ($value) => $value !== null && $value !== ''),
        ]);
        $po->forceFill([
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'po_number' => $this->numbers->next('purchase_order', $project),
            'revision_no' => 0,
            'status' => PurchaseOrderStatus::Draft,
            'vendor_state_code' => $vendor->state_code,
            'tax_type' => $this->gst->taxType($vendor->state_code, $po->place_of_supply_state),
        ])->save();

        return $po;
    }

    private function activeVendor(int $vendorId): Vendor
    {
        $vendor = Vendor::query()->find($vendorId);
        if ($vendor === null || ! $vendor->is_active) {
            throw ValidationException::withMessages(['vendor_id' => 'Select an active vendor of this company.']);
        }

        return $vendor;
    }

    private function reopenRfq(PurchaseOrder $po): void
    {
        if ($po->rfq_id === null) {
            return;
        }

        $rfq = Rfq::query()->whereKey($po->rfq_id)->lockForUpdate()->first();
        $hasOther = $rfq?->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Cancelled->value)->exists();
        if ($rfq?->status === RfqStatus::Closed && ! $hasOther) {
            $rfq->forceFill(['status' => RfqStatus::Evaluated, 'closed_at' => null])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $header = array_intersect_key($data, array_flip([
            'po_date', 'delivery_date', 'billing_address', 'shipping_address', 'place_of_supply_state',
            'payment_terms', 'terms', 'remarks', 'freight_amount', 'other_charges',
        ]));
        foreach (['freight_amount', 'other_charges'] as $key) {
            if (array_key_exists($key, $header)) {
                $header[$key] = Decimal::of((string) ($header[$key] ?? '0'))->toMoney();
            }
        }

        return $header;
    }

    private function companyAddress(Company $company): string
    {
        return collect([
            $company->legal_name ?: $company->name,
            $company->address,
            trim(($company->city ?? '').($company->pincode ? ' - '.$company->pincode : '')),
            $company->gstin ? 'GSTIN: '.$company->gstin : null,
        ])->filter()->implode("\n");
    }

    private function projectAddress(Project $project): string
    {
        return collect([$project->name, $project->address, $project->city])->filter()->implode("\n");
    }
}
