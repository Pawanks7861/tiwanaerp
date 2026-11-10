<?php

namespace App\Http\Requests\Api\Site;

use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSiteDiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [SiteDiary::class, $this->project()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hours = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'];
        $qty = ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'];

        return [
            'uuid' => ['required', 'string', 'uuid'],
            'diary_date' => ['required', 'date', 'before_or_equal:today'],
            'weather' => ['nullable', 'string', 'max:50'],
            'temperature' => ['nullable', 'numeric', 'decimal:0,2', 'between:-60,70'],
            'work_location' => ['nullable', 'string', 'max:150'],
            'work_performed' => ['nullable', 'string', 'max:5000'],
            'issues' => ['nullable', 'string', 'max:5000'],
            'safety_incidents' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'site_id' => ['nullable', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'captured_at' => ['nullable', 'date'],
            'submit' => ['sometimes', 'boolean'],

            'work_items' => ['nullable', 'array', 'max:100'],
            'work_items.*.task_id' => ['nullable', 'integer'],
            'work_items.*.boq_item_id' => ['nullable', 'integer'],
            'work_items.*.subcontractor_id' => ['nullable', 'integer'],
            'work_items.*.description' => ['nullable', 'string', 'max:500'],
            'work_items.*.quantity' => $qty,
            'work_items.*.unit_id' => ['nullable', 'integer'],

            'labour' => ['nullable', 'array', 'max:100'],
            'labour.*.labour_trade_id' => ['required', 'integer'],
            'labour.*.subcontractor_id' => ['nullable', 'integer'],
            'labour.*.headcount' => ['required', 'integer', 'min:1', 'max:100000'],
            'labour.*.hours' => $hours,
            'labour.*.remarks' => ['nullable', 'string', 'max:255'],

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

            'photos' => ['nullable', 'array', 'max:30'],
            'photos.*' => ['file', 'max:'.(int) config('uploads.photo_max_kb'), 'extensions:'.implode(',', config('uploads.image_extensions'))],
            'photo_uuids' => ['nullable', 'array', 'max:30'],
            'photo_uuids.*' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        if (array_key_exists('labour', $data) || array_key_exists('labours', $data)) {
            $data['labours'] = $data['labour'] ?? $data['labours'] ?? [];
        }
        unset($data['labour'], $data['photos'], $data['photo_uuids'], $data['submit']);

        foreach (['latitude', 'longitude'] as $field) {
            if (isset($data[$field]) && is_numeric($data[$field])) {
                $data[$field] = round((float) $data[$field], 7);
            }
        }

        return $data;
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
