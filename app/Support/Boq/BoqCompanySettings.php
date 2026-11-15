<?php

namespace App\Support\Boq;

use App\Models\Core\Company;
use App\Support\Tenancy\CurrentCompany;

final class BoqCompanySettings
{
    public const KEY = 'boq.advanced_calculations_enabled';

    public static function enabled(?Company $company = null): bool
    {
        $company ??= app(CurrentCompany::class)->get();
        if ($company === null) {
            return false;
        }

        // Explicit company: bypass tenant scope so reads work outside CurrentCompany context.
        $value = $company->settings()
            ->withoutGlobalScopes()
            ->where('key', self::KEY)
            ->value('value');

        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(Company $company, bool $enabled): void
    {
        $company->settings()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                ['key' => self::KEY],
                ['value' => $enabled],
            );
    }
}
