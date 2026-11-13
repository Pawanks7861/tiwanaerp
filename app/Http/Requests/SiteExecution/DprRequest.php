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
        $qty = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'];
        $qtyPositive = ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'];

        return [
            'engineer_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'weather' => ['nullable', 'string', 'max:150'],
            'site_issues' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'work_performed' => ['nullable', 'string', 'max:10000'],
            'constraints_summary' => ['nullable', 'string', 'max:10000'],
            'department' => ['nullable', 'string', 'max:120'],
            'agency_name' => ['nullable', 'string', 'max:160'],

            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.work_area' => ['nullable', 'string', 'max:160'],
            'items.*.activity_name_snapshot' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.planned_start_date' => ['nullable', 'date'],
            'items.*.planned_end_date' => ['nullable', 'date'],
            'items.*.today_target_qty' => $qty,
            'items.*.executed_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'items.*.hold_quantity' => $qty,
            'items.*.hold_reason' => ['nullable', 'string', 'max:80'],
            'items.*.hold_since' => ['nullable', 'date'],
            'items.*.hold_responsible' => ['nullable', 'string', 'max:120'],
            'items.*.hold_expected_resolution' => ['nullable', 'date'],
            'items.*.hold_status' => ['nullable', 'string', 'max:40'],
            'items.*.on_hold' => ['nullable', 'boolean'],
            'items.*.shortfall_reason' => ['nullable', 'string', 'max:500'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],

            'labours' => ['nullable', 'array', 'max:200'],
            'labours.*.labour_trade_id' => ['required', 'integer'],
            'labours.*.subcontractor_id' => ['nullable', 'integer'],
            'labours.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'labours.*.skilled' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'labours.*.unskilled' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'labours.*.operator_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'labours.*.helper_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'labours.*.hours' => $hours,
            'labours.*.remarks' => ['nullable', 'string', 'max:255'],

            'equipment' => ['nullable', 'array', 'max:200'],
            'equipment.*.equipment_type_id' => ['nullable', 'integer'],
            'equipment.*.equipment_id' => ['nullable', 'integer'],
            'equipment.*.description' => ['nullable', 'string', 'max:150'],
            'equipment.*.operator_name' => ['nullable', 'string', 'max:120'],
            'equipment.*.start_time' => ['nullable', 'date_format:H:i'],
            'equipment.*.end_time' => ['nullable', 'date_format:H:i'],
            'equipment.*.duration_hours' => $hours,
            'equipment.*.working_hours' => $hours,
            'equipment.*.idle_hours' => $hours,
            'equipment.*.status' => ['nullable', 'string', 'max:40'],
            'equipment.*.remarks' => ['nullable', 'string', 'max:255'],

            'materials' => ['nullable', 'array', 'max:200'],
            'materials.*.material_id' => ['required', 'integer'],
            'materials.*.opening_qty' => $qty,
            'materials.*.received_qty' => $qty,
            'materials.*.issued_qty' => $qty,
            'materials.*.closing_qty' => $qty,
            'materials.*.quantity' => $qtyPositive,
            'materials.*.unit_id' => ['nullable', 'integer'],
            'materials.*.remarks' => ['nullable', 'string', 'max:255'],

            'constraints' => ['nullable', 'array', 'max:200'],
            'constraints.*.category' => ['required', 'string', 'max:80'],
            'constraints.*.description' => ['required', 'string', 'max:5000'],
            'constraints.*.work_area' => ['nullable', 'string', 'max:160'],
            'constraints.*.task_id' => ['nullable', 'integer'],
            'constraints.*.responsible' => ['nullable', 'string', 'max:160'],
            'constraints.*.priority' => ['nullable', 'string', 'max:40'],
            'constraints.*.raised_date' => ['nullable', 'date'],
            'constraints.*.expected_resolution' => ['nullable', 'date'],
            'constraints.*.status' => ['nullable', 'string', 'max:40'],
            'constraints.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'engineer_id' => 'engineer',
            'items.*.executed_qty' => 'executed quantity',
            'items.*.today_target_qty' => "today's target",
            'items.*.hold_quantity' => 'hold quantity',
            'labours.*.labour_trade_id' => 'trade',
            'labours.*.headcount' => 'headcount',
            'materials.*.material_id' => 'item',
            'materials.*.quantity' => 'quantity',
            'materials.*.issued_qty' => 'issued quantity',
            'constraints.*.category' => 'constraint category',
            'constraints.*.description' => 'constraint description',
        ];
    }

    /**
     * @return list<string>
     */
    protected function decimalFields(): array
    {
        return [
            'items.*.executed_qty', 'items.*.today_target_qty', 'items.*.hold_quantity',
            'labours.*.hours', 'equipment.*.working_hours', 'equipment.*.idle_hours', 'equipment.*.duration_hours',
            'materials.*.quantity', 'materials.*.opening_qty', 'materials.*.received_qty', 'materials.*.issued_qty', 'materials.*.closing_qty',
        ];
    }
}
