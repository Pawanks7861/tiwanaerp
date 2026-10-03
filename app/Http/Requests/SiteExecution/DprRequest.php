<?php

namespace App\Http\Requests\SiteExecution;

use App\Http\Requests\Concerns\NormalizesDecimals;
use App\Models\SiteExecution\Dpr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Edit of a draft DPR (web and API). Project ownership of tasks, BOQ lines and masters is
 * enforced by DprService; planned / cumulative / balance are never accepted from the client.
 */
class DprRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $dpr = $this->route('dpr');

        return $dpr instanceof Dpr && Gate::allows('update', $dpr);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hours = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'];

        return [
            'engineer_id' => ['nullable', 'integer'],
            'weather' => ['nullable', 'string', 'max:150'],
            'site_issues' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:5000'],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.executed_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],

            'labours' => ['nullable', 'array', 'max:200'],
            'labours.*.labour_trade_id' => ['required', 'integer'],
            'labours.*.subcontractor_id' => ['nullable', 'integer'],
            'labours.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'labours.*.hours' => $hours,
            'labours.*.remarks' => ['nullable', 'string', 'max:255'],

            'equipment' => ['nullable', 'array', 'max:200'],
            'equipment.*.equipment_type_id' => ['nullable', 'integer'],
            'equipment.*.description' => ['nullable', 'string', 'max:150'],
            'equipment.*.working_hours' => $hours,
            'equipment.*.idle_hours' => $hours,

            'materials' => ['nullable', 'array', 'max:200'],
            'materials.*.material_id' => ['required', 'integer'],
            'materials.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'materials.*.unit_id' => ['nullable', 'integer'],
            'materials.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'engineer_id' => 'engineer',
            'items.*.executed_qty' => 'executed quantity',
            'labours.*.labour_trade_id' => 'trade',
            'labours.*.headcount' => 'headcount',
            'materials.*.material_id' => 'item',
            'materials.*.quantity' => 'quantity',
        ];
    }

    /**
     * @return list<string>
     */
    protected function decimalFields(): array
    {
        return ['items.*.executed_qty', 'labours.*.hours', 'equipment.*.working_hours', 'equipment.*.idle_hours', 'materials.*.quantity'];
    }
}
