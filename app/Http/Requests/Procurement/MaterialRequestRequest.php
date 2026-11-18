<?php

namespace App\Http\Requests\Procurement;

use App\Enums\Procurement\MaterialRequestSourceType;
use App\Enums\Procurement\RequestPriority;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Rules\ExistsInCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mr = $this->route('materialRequest');

        return $mr instanceof MaterialRequest
            ? Gate::allows('update', $mr)
            : Gate::allows('create', [MaterialRequest::class, $this->project()]);
    }

    protected function prepareForValidation(): void
    {
        // Legacy API / pre-source clients: default to General with an explicit reason.
        if (! $this->filled('source_type')) {
            $this->merge(['source_type' => MaterialRequestSourceType::General->value]);
        }
        if ((string) $this->input('source_type') === MaterialRequestSourceType::General->value
            && ! $this->filled('source_reason')) {
            $this->merge(['source_reason' => 'General / unplanned material request']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->project()->id;
        $source = (string) $this->input('source_type', MaterialRequestSourceType::General->value);

        return [
            'site_id' => ['nullable', new ExistsInCompany(Site::class, fn (Builder $q) => $q->where('project_id', $projectId)->where('is_active', true))],
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'priority' => ['required', Rule::enum(RequestPriority::class)],
            'source_type' => ['required', Rule::enum(MaterialRequestSourceType::class)],
            'boq_id' => [$source === MaterialRequestSourceType::Boq->value ? 'required' : 'nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'drawing_revision_id' => [$source === MaterialRequestSourceType::Drawing->value ? 'required' : 'nullable', 'integer'],
            'source_reason' => [$source === MaterialRequestSourceType::General->value ? 'required' : 'nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.material_id' => ['required', ExistsInCompany::active(Material::class)],
            'items.*.unit_id' => ['required', ExistsInCompany::active(Unit::class)],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', new ExistsInCompany(ProjectTask::class, fn (Builder $q) => $q->where('project_id', $projectId))],
            'items.*.project_cost_head_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.material_id' => 'item',
            'items.*.unit_id' => 'unit',
            'items.*.task_id' => 'task',
            'items.*.quantity' => 'quantity',
            'site_id' => 'site',
            'source_type' => 'source',
            'boq_id' => 'BOQ',
            'drawing_revision_id' => 'drawing revision',
            'source_reason' => 'reason',
        ];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
