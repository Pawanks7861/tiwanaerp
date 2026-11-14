<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Masters\IndianFormats;
use App\Support\Navigation\SidebarCatalog;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'mobile' => is_string($this->input('mobile')) && trim($this->input('mobile')) !== '' ? trim($this->input('mobile')) : null,
            'roles' => array_values(array_filter((array) $this->input('roles', []), fn ($v) => is_scalar($v))),
            'permissions' => array_values(array_filter((array) $this->input('permissions', []), fn ($v) => is_string($v) && $v !== '')),
            'menu_items' => array_values(array_unique(array_filter(
                (array) $this->input('menu_items', []),
                fn ($v) => is_string($v) && $v !== ''
            ))),
        ]);

        $actor = $this->user();
        if ($actor === null || $actor->isSuperAdmin()) {
            return;
        }

        $target = $this->route('user');
        if (! ($target instanceof User)) {
            return;
        }

        $held = $actor->getAllPermissions()->pluck('name');
        $preserved = $target->getDirectPermissions()->pluck('name')->diff($held);
        if ($preserved->isEmpty()) {
            return;
        }

        $this->merge([
            'permissions' => collect($this->input('permissions', []))
                ->merge($preserved)
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        $target = $this->route('user');
        $isUpdate = $target instanceof User;

        return [
            'name' => ['required', 'string', 'max:150'],
            // On create an existing email is allowed: the existing user is added to this company.
            'email' => array_filter([
                'required', 'email:rfc', 'max:255',
                $isUpdate ? Rule::unique('users', 'email')->ignore($target->id) : null,
            ]),
            'mobile' => ['nullable', 'string', 'regex:'.IndianFormats::MOBILE],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            'roles' => ['present', 'array', 'max:10'],
            'roles.*' => ['integer', 'distinct'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::all())],
            'menu_items' => ['present', 'array'],
            'menu_items.*' => ['string', 'distinct', Rule::in(SidebarCatalog::keys())],
        ];
    }

    /**
     * A user manager cannot newly grant permissions they do not hold.
     * Direct permissions already on the user that the editor does not hold may remain.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $actor = $this->user();
                if ($actor === null || $actor->isSuperAdmin() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $held = $actor->getAllPermissions()->pluck('name');
                $target = $this->route('user');
                $existing = $target instanceof User
                    ? $target->getDirectPermissions()->pluck('name')
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
