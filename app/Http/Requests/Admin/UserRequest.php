<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Masters\IndianFormats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
        ];
    }
}
