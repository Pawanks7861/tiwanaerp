<?php

namespace App\Http\Requests\Boq;

use App\Enums\Boq\ResourceType;
use App\Http\Requests\Concerns\NormalizesDecimals;
use App\Models\Boq\RateAnalysis;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Rules\ExistsInCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RateAnalysisRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $analysis = $this->route('rateAnalysis');

        return $analysis instanceof RateAnalysis
            ? $this->user()->can('update', $analysis)
            : $this->user()->can('create', [RateAnalysis::class, $this->route('project')]);
    }

    protected function decimalFields(): array
    {
        return ['output_quantity', 'overhead_percent', 'profit_percent', 'items.*.quantity', 'items.*.wastage_percent', 'items.*.rate'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_id' => ['required', ExistsInCompany::active(Unit::class)],
            'output_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,4'],
            'overhead_percent' => ['nullable', 'numeric', 'min:0', 'max:999', 'decimal:0,4'],
            'profit_percent' => ['nullable', 'numeric', 'min:0', 'max:999', 'decimal:0,4'],
            'items' => ['present', 'array', 'max:500'],
            'items.*.resource_type' => ['required', Rule::enum(ResourceType::class)],
            'items.*.material_id' => ['nullable', 'required_if:items.*.resource_type,material', ExistsInCompany::active(Material::class)],
            'items.*.labour_trade_id' => ['nullable', 'required_if:items.*.resource_type,labour', ExistsInCompany::active(LabourTrade::class)],
            'items.*.equipment_type_id' => ['nullable', 'required_if:items.*.resource_type,equipment', ExistsInCompany::active(EquipmentType::class)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_id' => ['nullable', ExistsInCompany::active(Unit::class)],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'items.*.rate' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
        ];
    }

    public function attributes(): array
    {
        return [
            'unit_id' => 'unit',
            'output_quantity' => 'output quantity',
            'items.*.material_id' => 'material',
            'items.*.labour_trade_id' => 'labour trade',
            'items.*.equipment_type_id' => 'equipment type',
            'items.*.description' => 'description',
            'items.*.quantity' => 'quantity',
            'items.*.rate' => 'rate',
        ];
    }

    public function messages(): array
    {
        return ['output_quantity.gt' => 'The output quantity must be greater than zero.'];
    }
}
