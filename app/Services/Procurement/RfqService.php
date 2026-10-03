<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\RfqStatus;
use App\Enums\Procurement\RfqVendorStatus;
use App\Models\Masters\Vendor;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\RfqVendor;
use App\Models\Projects\Project;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfqService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated header, items and vendor_ids
     */
    public function create(Project $project, array $data): Rfq
    {
        return DB::transaction(function () use ($project, $data) {
            $rfq = new Rfq($this->header($data));
            $rfq->forceFill([
                'project_id' => $project->id,
                'rfq_number' => $this->numbers->next('rfq', $project),
                'status' => RfqStatus::Draft,
            ])->save();

            $this->replaceItems($rfq, $data['items']);
            $this->syncVendors($rfq, $data['vendor_ids'] ?? []);

            return $rfq;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Rfq $rfq, array $data): Rfq
    {
        return DB::transaction(function () use ($rfq, $data) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            $rfq->assertEditable();
            $rfq->fill($this->header($data))->save();
            $this->replaceItems($rfq, $data['items']);
            $this->syncVendors($rfq, $data['vendor_ids'] ?? []);

            return $rfq;
        });
    }

    public function delete(Rfq $rfq): void
    {
        $rfq->assertEditable();
        $rfq->delete();
    }

    /**
     * Vendors may be invited until the RFQ is evaluated. Vendors that already quoted cannot be removed.
     *
     * @param  list<int|string>  $vendorIds
     */
    public function syncVendors(Rfq $rfq, array $vendorIds): void
    {
        if (! in_array($rfq->status, [RfqStatus::Draft, RfqStatus::Sent, RfqStatus::QuotesReceived], true)) {
            throw ValidationException::withMessages(['vendor_ids' => 'Vendors can no longer be changed on this RFQ.']);
        }

        $vendorIds = array_map('intval', $vendorIds);
        if (count($vendorIds) !== count(array_unique($vendorIds))) {
            throw ValidationException::withMessages(['vendor_ids' => 'A vendor can only be invited once.']);
        }

        DB::transaction(function () use ($rfq, $vendorIds) {
            $current = $rfq->vendors()->get()->keyBy('vendor_id');
            $quoted = $rfq->quotations()->pluck('vendor_id')->map(fn ($id) => (int) $id)->all();

            $removed = $current->keys()->diff($vendorIds);
            if ($removed->intersect($quoted)->isNotEmpty()) {
                throw ValidationException::withMessages(['vendor_ids' => 'A vendor that has already quoted cannot be removed.']);
            }

            $new = array_values(array_diff($vendorIds, $current->keys()->all()));
            if ($new !== [] && Vendor::query()->active()->whereKey($new)->count() !== count($new)) {
                throw ValidationException::withMessages(['vendor_ids' => 'Only active vendors of this company can be invited.']);
            }

            RfqVendor::query()->where('rfq_id', $rfq->id)->whereIn('vendor_id', $removed->all())->delete();

            $sent = $rfq->status !== RfqStatus::Draft;
            foreach ($new as $vendorId) {
                (new RfqVendor)->forceFill([
                    'rfq_id' => $rfq->id,
                    'vendor_id' => $vendorId,
                    'status' => $sent ? RfqVendorStatus::Sent : RfqVendorStatus::Invited,
                    'sent_at' => $sent ? now() : null,
                ])->save();
            }

            if ($removed->isNotEmpty() || $new !== []) {
                $rfq->writeAudit('vendors_changed', ['vendor_ids' => $current->keys()->values()->all()], ['vendor_ids' => $vendorIds]);
            }
        });
    }

    public function send(Rfq $rfq): void
    {
        DB::transaction(function () use ($rfq) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if ($rfq->status !== RfqStatus::Draft) {
                throw ValidationException::withMessages(['rfq' => 'Only draft RFQs can be sent.']);
            }
            if (! $rfq->items()->exists()) {
                throw ValidationException::withMessages(['rfq' => 'Add at least one item before sending the RFQ.']);
            }

            $vendorIds = $rfq->vendors()->pluck('vendor_id');
            if ($vendorIds->isEmpty()) {
                throw ValidationException::withMessages(['rfq' => 'Invite at least one vendor before sending the RFQ.']);
            }
            if (Vendor::query()->active()->whereKey($vendorIds)->count() !== $vendorIds->count()) {
                throw ValidationException::withMessages(['rfq' => 'One of the invited vendors is inactive. Remove it before sending.']);
            }

            $this->assertWithinRemaining($rfq->items()->get(), $rfq->id);

            RfqVendor::query()->where('rfq_id', $rfq->id)->update(['status' => RfqVendorStatus::Sent->value, 'sent_at' => now()]);
            $rfq->forceFill(['status' => RfqStatus::Sent, 'sent_at' => now()])->save();
        });
    }

    /**
     * sent ⇄ quotes_received follows whether any quotation exists.
     */
    public function refreshQuoteStatus(Rfq $rfq): void
    {
        $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
        if (! $rfq->status->acceptsQuotations()) {
            return;
        }

        $status = $rfq->quotations()->exists() ? RfqStatus::QuotesReceived : RfqStatus::Sent;
        if ($status !== $rfq->status) {
            $rfq->forceFill(['status' => $status])->save();
        }
    }

    public function close(Rfq $rfq): void
    {
        DB::transaction(function () use ($rfq) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! in_array($rfq->status, [RfqStatus::Sent, RfqStatus::QuotesReceived, RfqStatus::Evaluated], true)) {
                throw ValidationException::withMessages(['rfq' => 'Only sent or evaluated RFQs can be closed.']);
            }

            $rfq->forceFill(['status' => RfqStatus::Closed, 'closed_at' => now()])->save();
        });
    }

    public function cancel(Rfq $rfq, string $reason): void
    {
        DB::transaction(function () use ($rfq, $reason) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! $rfq->status->isOpen()) {
                throw ValidationException::withMessages(['rfq' => 'This RFQ is already closed or cancelled.']);
            }
            if ($rfq->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Cancelled->value)->exists()) {
                throw ValidationException::withMessages(['rfq' => 'A purchase order was created from this RFQ. Cancel the purchase order first.']);
            }

            $rfq->forceFill(['status' => RfqStatus::Cancelled, 'cancelled_reason' => $reason])->save();
        });
    }

    /**
     * Lines must reference approved MR lines of the same project, each at most once, and stay within
     * the remaining (not yet reserved) quantity.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceItems(Rfq $rfq, array $rows): void
    {
        $mrItemIds = array_map(fn ($row) => (int) $row['material_request_item_id'], $rows);
        if (count($mrItemIds) !== count(array_unique($mrItemIds))) {
            throw ValidationException::withMessages(['items' => 'Each material request line can only appear once on an RFQ.']);
        }

        $mrItems = $this->procurableItems($rfq->project_id, $mrItemIds);
        $rfq->items()->get()->each(function (RfqItem $item) use ($rfq) {
            $item->setRelation('rfq', $rfq);
            $item->delete();
        });

        foreach (array_values($rows) as $index => $row) {
            $mrItem = $mrItems->get((int) $row['material_request_item_id'])
                ?? throw ValidationException::withMessages(["items.{$index}.material_request_item_id" => 'Select an approved material request line of this project.']);

            $item = new RfqItem([
                'material_request_item_id' => $mrItem->id,
                'material_id' => $mrItem->material_id,
                'unit_id' => $mrItem->unit_id,
                'quantity' => Decimal::of((string) $row['quantity'])->toQuantity(),
                'required_date' => $row['required_date'] ?? $mrItem->materialRequest->required_date?->toDateString(),
                'specification' => $row['specification'] ?? null,
                'sort_order' => $index + 1,
            ]);
            $item->rfq_id = $rfq->id;
            $item->setRelation('rfq', $rfq);
            $item->save();
        }

        $this->assertWithinRemaining($rfq->items()->get(), $rfq->id);
    }

    /**
     * @param  Collection<int, RfqItem>  $items
     */
    private function assertWithinRemaining($items, int $rfqId): void
    {
        $mrItems = MaterialRequestItem::query()->whereKey($items->pluck('material_request_item_id')->filter())->get();
        $remaining = $this->quantities->remaining($mrItems, exceptRfqId: $rfqId);

        foreach ($items->values() as $index => $item) {
            if ($item->material_request_item_id === null) {
                continue;
            }
            if (Decimal::of($item->quantity)->greaterThan($remaining[$item->material_request_item_id])) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => "Only {$this->trim($remaining[$item->material_request_item_id])} remain to be procured on this material request line.",
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, MaterialRequestItem>
     */
    public function procurableItems(int $projectId, array $ids)
    {
        return MaterialRequestItem::query()
            ->whereKey($ids)
            ->whereHas('materialRequest', fn (Builder $q) => $q->where('project_id', $projectId)->whereIn('status', ['approved', 'partially_ordered']))
            ->with('materialRequest:id,project_id,required_date,status,request_number')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'title' => $data['title'] ?? null,
            'rfq_date' => $data['rfq_date'],
            'due_date' => $data['due_date'] ?? null,
            'required_date' => $data['required_date'] ?? null,
            'terms' => $data['terms'] ?? null,
        ];
    }

    private function trim(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}
