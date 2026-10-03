<?php

namespace App\Http\Requests\Projects;

use App\Enums\IndianState;
use App\Models\Crm\Client;
use App\Models\Projects\Project;
use App\Rules\CompanyMember;
use App\Rules\ExistsInCompany;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            ? $this->user()->can('update', $project)
            : $this->user()->can('create', Project::class);
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['code', 'name', 'project_type', 'city', 'description', 'address'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $data[$key] = $value === '' ? null : ($key === 'code' ? strtoupper($value) : $value);
            }
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $isUpdate = $project instanceof Project;

        return [
            'code' => [
                $isUpdate ? 'required' : 'nullable', 'string', 'max:12', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                Rule::unique('projects', 'code')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($isUpdate ? $project->id : null),
            ],
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['nullable', ExistsInCompany::active(Client::class)],
            'project_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'project_manager_id' => ['nullable', new CompanyMember],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectData(): array
    {
        $data = $this->validated();
        $data['contract_value'] = $data['contract_value'] ?? '0';

        return $data;
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'client',
            'project_manager_id' => 'project manager',
            'state_code' => 'state',
            'expected_end_date' => 'expected completion date',
        ];
    }
}
