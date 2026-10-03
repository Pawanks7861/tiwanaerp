<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The user ID must belong to an active, enabled member of the current company.
 */
class CompanyMember implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $companyId = app(CurrentCompany::class)->id();

        $valid = $companyId && is_scalar($value) && ctype_digit((string) $value) && User::query()
            ->whereKey((int) $value)
            ->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->exists();

        if (! $valid) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
