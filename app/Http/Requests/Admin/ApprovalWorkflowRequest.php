<?php

namespace App\Http\Requests\Admin;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Enums\ProjectRole;
use App\Models\Approval\ApprovalWorkflow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workflow = $this->existingWorkflow();

        return $workflow
            ? $this->user()->can('update', $workflow)
            : $this->user()->can('create', ApprovalWorkflow::class);
    }

    protected function prepareForValidation(): void
    {
        $steps = collect($this->input('steps', []))->map(function ($step) {
            $type = is_string($step['approver_type'] ?? null) ? $step['approver_type'] : null;

            return [
                'name' => is_string($step['name'] ?? null) ? trim($step['name']) : '',
                'approver_type' => $type,
                'user_id' => filled($step['user_id'] ?? null) ? (int) $step['user_id'] : null,
                'role_id' => filled($step['role_id'] ?? null) ? (int) $step['role_id'] : null,
                'project_role' => filled($step['project_role'] ?? null) ? (string) $step['project_role'] : null,
                'mode' => is_string($step['mode'] ?? null) && $step['mode'] !== '' ? $step['mode'] : ApprovalMode::Any->value,
            ];
        })->values()->all();

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'is_active' => $this->boolean('is_active'),
            'steps' => $steps,
        ]);
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:100'],
            'steps.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.mode' => ['required', Rule::enum(ApprovalMode::class)],
            'steps.*.user_id' => [
                'nullable',
                'integer',
                Rule::exists('company_user', 'user_id')->where('company_id', $companyId)->where('is_active', true),
            ],
            'steps.*.role_id' => [
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where('team_id', $companyId)->where('guard_name', 'web'),
            ],
            'steps.*.project_role' => ['nullable', 'string', Rule::enum(ProjectRole::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'steps.min' => 'Add at least one approval step.',
            'steps.*.name.required' => 'Each step needs a name.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->input('steps', []) as $index => $step) {
                    $type = ApproverType::from($step['approver_type']);
                    $key = "steps.{$index}";

                    if ($type === ApproverType::User && empty($step['user_id'])) {
                        $validator->errors()->add("{$key}.user_id", 'Choose the person who must approve this step.');
                    }
                    if ($type === ApproverType::Role && empty($step['role_id'])) {
                        $validator->errors()->add("{$key}.role_id", 'Choose the company role that must approve this step.');
                    }
                    if ($type === ApproverType::ProjectRole && empty($step['project_role'])) {
                        $validator->errors()->add("{$key}.project_role", 'Choose the project role that must approve this step.');
                    }
                }
            },
        ];
    }

    private function existingWorkflow(): ?ApprovalWorkflow
    {
        $type = (string) $this->route('type');

        return ApprovalWorkflow::query()
            ->where('document_type', $type)
            ->whereNull('project_id')
            ->first();
    }
}
