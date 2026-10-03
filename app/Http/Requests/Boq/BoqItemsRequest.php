<?php

namespace App\Http\Requests\Boq;

use App\Http\Requests\Concerns\NormalizesDecimals;
use App\Models\Masters\Unit;
use App\Rules\ExistsInCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Save of the BOQ editor grid. Section/BOQ ownership and rate-analysis eligibility are checked in
 * BoqService against the route BOQ.
 */
class BoqItemsRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('boq'));
    }

    protected function decimalFields(): array
    {
        return [
            'rows.*.quantity', 'rows.*.material_rate', 'rows.*.labour_rate', 'rows.*.equipment_rate',
            'rows.*.subcontract_rate', 'rows.*.margin_percent', 'rows.*.client_rate',
        ];
    }

    public function rules(): array
    {
        $rate = ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'];

        return [
            'rows' => ['present', 'array', 'max:5000'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.boq_section_id' => ['required', 'integer'],
            'rows.*.item_code' => ['nullable', 'string', 'max:30'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.description' => ['nullable', 'string', 'max:5000'],
            'rows.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'rows.*.unit_id' => ['required', ExistsInCompany::active(Unit::class)],
            'rows.*.quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'rows.*.material_rate' => $rate,
            'rows.*.labour_rate' => $rate,
            'rows.*.equipment_rate' => $rate,
            'rows.*.subcontract_rate' => $rate,
            'rows.*.margin_percent' => ['nullable', 'numeric', 'min:-100', 'max:999', 'decimal:0,4'],
            'rows.*.client_rate' => $rate,
            'rows.*.rate_analysis_id' => ['nullable', 'integer'],
            'rows.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'deleted_ids' => ['present', 'array'],
            'deleted_ids.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.name' => 'item name',
            'rows.*.unit_id' => 'unit',
            'rows.*.quantity' => 'quantity',
            'rows.*.boq_section_id' => 'section',
        ];
    }
}
