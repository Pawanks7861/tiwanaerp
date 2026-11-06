<?php

namespace App\Support\Procurement;

use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;

/**
 * Resolves procurement settings: project setting → company setting → config default.
 */
final class ProcurementSettings
{
    public const GRN_TOLERANCE_KEY = 'procurement.grn_tolerance_percent';

    public const RFQ_ENABLED_KEY = 'procurement.rfq_enabled';

    /**
     * Company setting wins when present. Otherwise the config default (false) applies.
     */
    public static function rfqEnabled(?Company $company = null): bool
    {
        $company ??= app(CurrentCompany::class)->get();
        if ($company !== null) {
            $value = $company->setting(self::RFQ_ENABLED_KEY);
            if ($value !== null) {
                return self::toBool($value);
            }
        }

        return self::toBool(config('procurement.rfq_enabled', false));
    }

    public static function setRfqEnabled(Company $company, bool $enabled): void
    {
        $company->settings()->updateOrCreate(
            ['key' => self::RFQ_ENABLED_KEY],
            ['value' => $enabled],
        );
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function grnTolerancePercent(Project $project): string
    {
        $value = $project->settings()->where('key', self::GRN_TOLERANCE_KEY)->value('value');

        if ($value === null) {
            $value = Company::query()->find($project->company_id)?->setting(self::GRN_TOLERANCE_KEY);
        }

        $value ??= config('procurement.grn_tolerance_percent', '0');

        return is_numeric($value) && (float) $value >= 0
            ? Decimal::of((string) $value)->round(Decimal::PERCENT_SCALE)->toString()
            : '0.0000';
    }
}
