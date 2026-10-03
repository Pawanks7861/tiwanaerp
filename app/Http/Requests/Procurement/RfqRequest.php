<?php

namespace App\Http\Requests\Procurement;

use App\Models\Masters\Vendor;
use App\Models\Procurement\Rfq;
use App\Rules\ExistsInCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rfq = $this->route('rfq');

        return $rfq instanceof Rfq
            ? Gate::allows('update', $rfq)
            : Gate::allows('create', [Rfq::class, $this->route('project')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'rfq_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:rfq_date'],
            'required_date' => ['nullable', 'date', 'after_or_equal:rfq_date'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_request_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.required_date' => ['nullable', 'date'],
            'items.*.specification' => ['nullable', 'string', 'max:2000'],
            'vendor_ids' => ['nullable', 'array', 'max:50'],
            'vendor_ids.*' => ['integer', 'distinct', ExistsInCompany::active(Vendor::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.material_request_item_id' => 'material request line',
            'items.*.quantity' => 'quantity',
            'vendor_ids.*' => 'vendor',
        ];
    }
}
