<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectRole;
use App\Rules\CompanyMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageTeam', $this->route('project'));
    }

    public function rules(): array
    {
        $isUpdate = $this->route('member') !== null;

        return [
            'user_id' => [$isUpdate ? 'prohibited' : 'required', new CompanyMember],
            'project_role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }

    public function attributes(): array
    {
        return ['user_id' => 'user', 'project_role' => 'role'];
    }
}
