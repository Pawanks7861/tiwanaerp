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

    /**
     * Re-attach permissions already on the role that the editor cannot grant, so omitting
     * them from the form (or a partial "Select all") cannot strip privileges they do not hold.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user === null || $user->isSuperAdmin()) {
            return;
        }

        $role = $this->route('role');
        if (! ($role instanceof Role)) {
            return;
        }

        $held = $user->getAllPermissions()->pluck('name');
        $preserved = $role->permissions()->pluck('name')->diff($held);
        if ($preserved->isEmpty()) {
            return;
        }

        $this->merge([
            'permissions' => collect($this->input('permissions', []))
                ->filter()
                ->merge($preserved)
                ->unique()
                ->values()
                ->all(),
        ]);
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
     * A role manager cannot newly grant permissions they do not hold (no privilege escalation).
     * Permissions already on the role that the editor does not hold are allowed to remain.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->user();
                if ($user === null || $user->isSuperAdmin() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $held = $user->getAllPermissions()->pluck('name');
                $role = $this->route('role');
                $existing = $role instanceof Role
                    ? $role->permissions()->pluck('name')
                    : collect();

                $illegal = collect($this->input('permissions', []))
                    ->diff($held)
                    ->diff($existing);

                if ($illegal->isNotEmpty()) {
                    $validator->errors()->add(
                        'permissions',
                        'You cannot grant permissions you do not hold: '.$illegal->take(5)->implode(', ')
                    );
                }
            },
        ];
    }
}
