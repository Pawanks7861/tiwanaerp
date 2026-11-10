<?php

namespace App\Http\Requests\Api\Site;

use App\Enums\Procurement\GrnExtraReason;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Services\Weighbridge\WeighbridgeReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grn = $this->route('grn');
        if ($grn instanceof Grn) {
            return Gate::allows('update', $grn);
        }

        $po = $this->purchaseOrder();

        return $po instanceof PurchaseOrder && Gate::allows('create', [Grn::class, $po]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = ! ($this->route('grn') instanceof Grn);
        $project = $this->project();

        return [
            'uuid' => [$creating ? 'required' : 'prohibited', 'string', 'uuid'],
            'purchase_order_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'received_date' => ['nullable', 'date', 'before_or_equal:today'],
            'receipt_date' => ['nullable', 'date', 'before_or_equal:today', 'required_without:received_date'],
            'warehouse_id' => ['nullable', new ExistsInCompany(Warehouse::class, fn (Builder $q) => $q
                ->where('is_active', true)->where(fn (Builder $w) => $w->whereNull('project_id')->orWhere('project_id', $project->id)))],
            'vendor_invoice_no' => ['nullable', 'string', 'max:50'],
            'vendor_invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'vendor_challan_no' => ['nullable', 'string', 'max:50'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'confirm_extra' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.received_quantity' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.received_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.rejected_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:500'],
            'items.*.extra_reason' => ['nullable', Rule::enum(GrnExtraReason::class)],
            'items.*.extra_remarks' => ['nullable', 'string', 'max:500'],
            'items.*.supplier_challan_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'challan_photo' => ['nullable', 'file', 'max:'.(int) config('uploads.photo_max_kb'), 'extensions:'.implode(',', [...config('uploads.image_extensions'), 'pdf'])],
            ...WeighbridgeReceiptService::rules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->normalized(requireUuid: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForUpdate(): array
    {
        return $this->normalized(requireUuid: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalized(bool $requireUuid): array
    {
        $data = $this->validated();
        $data['receipt_date'] = $data['receipt_date'] ?? $data['received_date'] ?? null;
        $data['items'] = array_map(function (array $row) {
            $poi = $row['purchase_order_item_id'] ?? $row['id'] ?? null;
            $received = $row['received_qty'] ?? $row['received_quantity'] ?? '0';

            return [
                'purchase_order_item_id' => $poi,
                'received_qty' => $received,
                'rejected_qty' => $row['rejected_qty'] ?? '0',
                'rejection_reason' => $row['rejection_reason'] ?? null,
                'extra_reason' => $row['extra_reason'] ?? null,
                'extra_remarks' => $row['extra_remarks'] ?? null,
                'supplier_challan_qty' => $row['supplier_challan_qty'] ?? null,
            ];
        }, $data['items']);

        unset($data['received_date'], $data['challan_photo']);
        if (! $requireUuid) {
            unset($data['uuid'], $data['purchase_order_id']);
        }

        return $data;
    }

    public function purchaseOrder(): ?PurchaseOrder
    {
        $grn = $this->route('grn');
        if ($grn instanceof Grn) {
            return PurchaseOrder::query()->where('project_id', $this->project()->id)->find($grn->purchase_order_id);
        }

        $id = $this->integer('purchase_order_id');
        if ($id < 1) {
            return null;
        }

        return PurchaseOrder::query()->where('project_id', $this->project()->id)->find($id);
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
