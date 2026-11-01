<?php

namespace App\Http\Requests\Api\Site;

use App\Models\Procurement\Grn;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $po = $this->purchaseOrder();

        return $po instanceof PurchaseOrder && Gate::allows('create', [Grn::class, $po]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string', 'uuid'],
            'purchase_order_id' => ['required', 'integer'],
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.received_quantity' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'challan_photo' => ['nullable', 'file', 'max:'.(int) config('uploads.photo_max_kb'), 'extensions:'.implode(',', [...config('uploads.image_extensions'), 'pdf'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        $data['receipt_date'] = $data['received_date'];
        $data['items'] = array_map(fn (array $row) => [
            'purchase_order_item_id' => $row['id'],
            'received_qty' => $row['received_quantity'],
        ], $data['items']);
        unset($data['received_date'], $data['challan_photo']);

        return $data;
    }

    public function purchaseOrder(): ?PurchaseOrder
    {
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
