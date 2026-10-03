<?php

namespace App\Http\Requests\Procurement;

use App\Enums\Procurement\RequestPriority;
use App\Models\Boq\BoqItem;
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
use Illuminate\Validation\Validator;

class MaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mr = $this->route('materialRequest');

        return $mr instanceof MaterialRequest
            ? Gate::allows('update', $mr)
            : Gate::allows('create', [MaterialRequest::class, $this->project()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->project()->id;

        return [
            'site_id' => ['nullable', new ExistsInCompany(Site::class, fn (Builder $q) => $q->where('project_id', $projectId)->where('is_active', true))],
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'priority' => ['required', Rule::enum(RequestPriority::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.material_id' => ['required', ExistsInCompany::active(Material::class)],
            'items.*.unit_id' => ['required', ExistsInCompany::active(Unit::class)],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', new ExistsInCompany(ProjectTask::class, fn (Builder $q) => $q->where('project_id', $projectId))],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * BOQ links must point at the project's current approved BOQ (or keep the link a line already had).
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $ids = collect($this->input('items', []))->pluck('boq_item_id')->filter()->map(fn ($id) => (int) $id)->unique();
            if ($ids->isEmpty()) {
                return;
            }

            $allowed = BoqItem::query()->whereKey($ids)
                ->whereHas('boq', fn (Builder $q) => $q->where('project_id', $this->project()->id)->where('is_current', true)->where('status', 'approved'))
                ->pluck('id');

            $mr = $this->route('materialRequest');
            if ($mr instanceof MaterialRequest) {
                $allowed = $allowed->merge($mr->items()->whereNotNull('boq_item_id')->pluck('boq_item_id'));
            }

            foreach ($this->input('items', []) as $index => $row) {
                if (! empty($row['boq_item_id']) && ! $allowed->contains((int) $row['boq_item_id'])) {
                    $validator->errors()->add("items.{$index}.boq_item_id", 'Select a line of the current approved BOQ of this project.');
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'items.*.material_id' => 'item',
            'items.*.unit_id' => 'unit',
            'items.*.task_id' => 'task',
            'items.*.quantity' => 'quantity',
            'site_id' => 'site',
        ];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
