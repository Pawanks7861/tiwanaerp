<?php

namespace App\Http\Requests\SiteExecution;

use App\Http\Requests\Concerns\NormalizesDecimals;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Shape of a site diary (web and API). Tenant / project ownership of every referenced id is
 * enforced by SiteDiaryService.
 */
class SiteDiaryRequest extends FormRequest
{
    use NormalizesDecimals {
        prepareForValidation as normaliseDecimals;
    }

    public function authorize(): bool
    {
        $diary = $this->route('siteDiary');

        return $diary instanceof SiteDiary
            ? Gate::allows('update', $diary)
            : Gate::allows('create', [SiteDiary::class, $this->project()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $qty = ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'];
        $hours = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'];

        return [
            'uuid' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'integer'],
            'diary_date' => ['required', 'date', 'before_or_equal:today'],
            'weather' => ['nullable', 'string', 'max:50'],
            'temperature' => ['nullable', 'numeric', 'decimal:0,2', 'between:-60,70'],
            'work_location' => ['nullable', 'string', 'max:150'],
            'work_performed' => ['nullable', 'string', 'max:5000'],
            'issues' => ['nullable', 'string', 'max:5000'],
            'safety_incidents' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'captured_at' => ['nullable', 'date'],

            'work_items' => ['nullable', 'array', 'max:100'],
            'work_items.*.task_id' => ['nullable', 'integer'],
            'work_items.*.boq_item_id' => ['nullable', 'integer'],
            'work_items.*.subcontractor_id' => ['nullable', 'integer'],
            'work_items.*.description' => ['nullable', 'string', 'max:500'],
            'work_items.*.quantity' => $qty,
            'work_items.*.unit_id' => ['nullable', 'integer'],

            'labours' => ['nullable', 'array', 'max:100'],
            'labours.*.labour_trade_id' => ['required', 'integer'],
            'labours.*.subcontractor_id' => ['nullable', 'integer'],
            'labours.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'labours.*.hours' => $hours,
            'labours.*.remarks' => ['nullable', 'string', 'max:255'],

            'equipment' => ['nullable', 'array', 'max:100'],
            'equipment.*.equipment_type_id' => ['nullable', 'integer'],
            'equipment.*.description' => ['nullable', 'string', 'max:150'],
            'equipment.*.working_hours' => $hours,
            'equipment.*.idle_hours' => $hours,

            'materials' => ['nullable', 'array', 'max:100'],
            'materials.*.material_id' => ['required', 'integer'],
            'materials.*.quantity' => $qty,
            'materials.*.unit_id' => ['nullable', 'integer'],
            'materials.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'site_id' => 'site',
            'work_items.*.quantity' => 'quantity',
            'work_items.*.description' => 'description',
            'labours.*.labour_trade_id' => 'trade',
            'labours.*.headcount' => 'headcount',
            'labours.*.hours' => 'hours',
            'equipment.*.working_hours' => 'working hours',
            'equipment.*.idle_hours' => 'idle hours',
            'materials.*.material_id' => 'item',
            'materials.*.quantity' => 'quantity',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseDecimals();

        // Browser geolocation reports up to 15 decimals; the columns keep 7 (about 1 cm).
        foreach (['latitude', 'longitude'] as $field) {
            if (is_numeric($this->input($field))) {
                $this->merge([$field => round((float) $this->input($field), 7)]);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function decimalFields(): array
    {
        return [
            'temperature', 'work_items.*.quantity', 'labours.*.hours', 'equipment.*.working_hours',
            'equipment.*.idle_hours', 'materials.*.quantity',
        ];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
