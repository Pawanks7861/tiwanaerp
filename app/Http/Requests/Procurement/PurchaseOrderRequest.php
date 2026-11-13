<?php

namespace App\Http\Requests\Procurement;

use App\Enums\IndianState;
use App\Models\Core\DocumentTermsTemplate;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vendor;
use App\Models\Procurement\PurchaseOrder;
use App\Rules\ExistsInCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Direct PO creation and draft PO edits. tax_type, totals and line amounts are never accepted from
 * the client; GstCalculator derives them on the server.
 */
class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $po = $this->route('purchaseOrder');

        return $po instanceof PurchaseOrder
            ? Gate::allows('update', $po)
            : Gate::allows('create', [PurchaseOrder::class, $this->route('project')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $po = $this->route('purchaseOrder');
        $creating = ! $po instanceof PurchaseOrder;
        $direct = $creating || $po->rfq_id === null;

        return [
            'vendor_id' => $creating ? ['required', ExistsInCompany::active(Vendor::class)] : ['prohibited'],
            'direct_justification' => $direct ? ['required', 'string', 'min:10', 'max:2000'] : ['prohibited'],
            'po_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:po_date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reference_date' => ['nullable', 'date'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'consignee_name' => ['nullable', 'string', 'max:255'],
            'consignee_email' => ['nullable', 'email', 'max:150'],
            'consignee_gstin' => ['nullable', 'string', 'max:20'],
            'place_of_supply_state' => ['required', Rule::enum(IndianState::class)],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'document_terms_template_id' => ['nullable', 'integer', new ExistsInCompany(
                DocumentTermsTemplate::class,
                fn ($q) => $q->where('document_type', 'purchase_order')->where('is_active', true)
            )],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'other_references' => ['nullable', 'string', 'max:2000'],
            'dispatched_through' => ['nullable', 'string', 'max:150'],
            'destination' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'freight_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer', 'distinct'],
            'items.*.material_request_item_id' => ['nullable', 'required_without:items.*.id', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'items.*.brand' => ['nullable', 'string', 'max:100'],
            'items.*.make' => ['nullable', 'string', 'max:100'],
            'items.*.due_date' => ['nullable', 'date'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'items.*.tax_rate_id' => ['nullable', new ExistsInCompany(TaxRate::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'vendor_id' => 'vendor',
            'place_of_supply_state' => 'place of supply',
            'document_terms_template_id' => 'terms template',
            'items.*.material_request_item_id' => 'material request line',
            'items.*.quantity' => 'quantity',
            'items.*.rate' => 'rate',
            'items.*.discount_percent' => 'discount',
            'items.*.tax_rate_id' => 'tax rate',
            'items.*.due_date' => 'due on',
        ];
    }
}
