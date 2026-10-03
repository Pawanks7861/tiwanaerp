<?php

namespace App\Support\Procurement;

use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;

/**
 * Resolves procurement settings: project setting → company setting → config default.
 */
final class ProcurementSettings
{
    public const GRN_TOLERANCE_KEY = 'procurement.grn_tolerance_percent';

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
