<?php

namespace App\Http\Requests\Admin;

use App\Enums\IndianState;
use App\Models\Core\Company;
use App\Support\Masters\IndianFormats;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Company profile. Used by super admins (create / edit any company) and by company admins
 * (settings of the current company, without code / active / admin fields).
 */
class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company') ?? $this->companyFromSettings();

        if ($this->routeIs('admin.company.*')) {
            return $this->user()->can('manageSettings', $company);
        }

        return $company instanceof Company
            ? $this->user()->can('update', $company)
            : $this->user()->can('create', Company::class);
    }

    protected function prepareForValidation(): void
    {
        $upper = fn ($v) => is_string($v) && trim($v) !== '' ? strtoupper(trim($v)) : null;
        $this->merge([
            'code' => $upper($this->input('code')),
            'gstin' => $upper($this->input('gstin')),
            'pan' => $upper($this->input('pan')),
            'admin_email' => is_string($this->input('admin_email')) ? strtolower(trim($this->input('admin_email'))) : null,
        ]);
    }

    public function rules(): array
    {
        $company = $this->route('company');
        $isPlatform = ! $this->routeIs('admin.company.*');

        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.IndianFormats::GSTIN],
            'pan' => ['nullable', 'string', 'size:10', 'regex:'.IndianFormats::PAN],
            'state_code' => ['required', Rule::enum(IndianState::class)],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'regex:'.IndianFormats::PINCODE],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ];

        if ($isPlatform) {
            $rules['code'] = ['required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                Rule::unique('companies', 'code')->ignore($company?->id)];
            $rules['is_active'] = ['boolean'];
            if ($company === null) {
                $rules['admin_name'] = ['required', 'string', 'max:150'];
                $rules['admin_email'] = ['required', 'email:rfc', 'max:255'];
                $rules['admin_password'] = ['nullable', 'string', 'min:8'];
            }
        }

        return $rules;
    }

    private function companyFromSettings(): ?Company
    {
        return app(CurrentCompany::class)->get();
    }
}
