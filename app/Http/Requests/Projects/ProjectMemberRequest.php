<?php

namespace App\Http\Requests\Projects;

use App\Models\Core\Role;
use App\Rules\CompanyMember;
use App\Support\Tenancy\CurrentCompany;
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
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return [
            'user_id' => [$isUpdate ? 'prohibited' : 'required', new CompanyMember],
            'company_role_id' => [
                'required',
                'integer',
                Rule::exists(Role::class, 'id')->where(fn ($q) => $q->where('team_id', $companyId)),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['user_id' => 'user', 'company_role_id' => 'role'];
    }
}
