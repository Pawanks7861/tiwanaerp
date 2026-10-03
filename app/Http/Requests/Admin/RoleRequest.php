<?php

namespace App\Http\Requests\Admin;

use App\Models\Core\Role;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? $this->user()->can('update', $role)
            : $this->user()->can('create', Role::class);
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                Rule::requiredIf(! ($role instanceof Role && $role->is_system)),
                Rule::prohibitedIf($role instanceof Role && $role->is_system && $this->input('name') !== $role->name),
                'string', 'max:100',
                Rule::unique('roles', 'name')
                    ->where('team_id', app(CurrentCompany::class)->id())
                    ->where('guard_name', 'web')
                    ->ignore($role instanceof Role ? $role->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::all())],
        ];
    }

    public function messages(): array
    {
        return ['name.prohibited' => 'System role names cannot be changed.'];
    }

    /**
     * A role manager cannot grant permissions they do not hold (no privilege escalation).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->user();
                if ($user->isSuperAdmin() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $missing = collect($this->input('permissions', []))->diff($user->getAllPermissions()->pluck('name'));
                if ($missing->isNotEmpty()) {
                    $validator->errors()->add('permissions', 'You cannot grant permissions you do not hold: '.$missing->take(5)->implode(', '));
                }
            },
        ];
    }
}
