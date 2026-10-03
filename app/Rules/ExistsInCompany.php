<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-safe replacement for "exists:": the record must exist in the CURRENT company
 * (the model's CompanyScope applies), not be soft deleted, and optionally be active.
 */
class ExistsInCompany implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder): void)|null  $constraint
     */
    public function __construct(
        private readonly string $model,
        private readonly ?Closure $constraint = null,
        private readonly bool $activeOnly = false,
    ) {}

    public static function active(string $model, ?Closure $constraint = null): self
    {
        return new self($model, $constraint, activeOnly: true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $query = $this->model::query()->whereKey((int) $value);

        if ($this->activeOnly) {
            $query->where('is_active', true);
        }

        if ($this->constraint) {
            ($this->constraint)($query);
        }

        if (! $query->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
