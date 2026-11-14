<?php

namespace App\Services\Boq;

use App\Enums\Boq\RateComparisonGstMode;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative Advanced BOQ rate comparison maths (Sheet2-style).
 * Never auto-awards a vendor.
 */
class RateComparisonCalculator
{
    /**
     * @param  array{
     *   quoted_rate?: string|null,
     *   gst_mode?: string|null,
     *   gst_percent?: string|null,
     *   internal_cost_rate?: string|null,
     *   approved_boq_rate?: string|null,
     * }  $input
     * @return array{
     *   quoted_rate: string,
     *   gst_mode: string,
     *   gst_percent: string|null,
     *   effective_rate: string,
     *   internal_cost_rate_snapshot: string,
     *   approved_boq_rate_snapshot: string,
     *   variance_vs_internal: string,
     *   variance_vs_internal_percent: string|null,
     *   variance_vs_approved: string,
     *   variance_vs_approved_percent: string|null
     * }
     */
    public static function quote(array $input): array
    {
        $quoted = Decimal::of($input['quoted_rate'] ?? '0')->round(Decimal::RATE_SCALE);
        if ($quoted->isNegative()) {
            throw ValidationException::withMessages(['quoted_rate' => 'Quoted rate cannot be negative.']);
        }

        $mode = RateComparisonGstMode::tryFrom((string) ($input['gst_mode'] ?? RateComparisonGstMode::Inclusive->value))
            ?? throw ValidationException::withMessages(['gst_mode' => 'Choose inclusive or exclusive GST.']);

        $gstPercent = null;
        if (array_key_exists('gst_percent', $input) && $input['gst_percent'] !== null && $input['gst_percent'] !== '') {
            $gstPercent = Decimal::of($input['gst_percent'])->round(Decimal::PERCENT_SCALE);
            if ($gstPercent->isNegative() || $gstPercent->greaterThan('100')) {
                throw ValidationException::withMessages(['gst_percent' => 'GST percent must be between 0 and 100.']);
            }
        }

        // Effective rate for comparison stays on the quoted basis (as entered).
        // Inclusive vs exclusive is metadata for display; do not silently strip GST.
        $effective = $quoted;

        $internal = Decimal::of($input['internal_cost_rate'] ?? '0')->round(Decimal::RATE_SCALE);
        $approved = Decimal::of($input['approved_boq_rate'] ?? '0')->round(Decimal::RATE_SCALE);

        $vsInternal = $effective->minus($internal)->round(Decimal::RATE_SCALE);
        $vsApproved = $effective->minus($approved)->round(Decimal::RATE_SCALE);

        return [
            'quoted_rate' => $quoted->toRate(),
            'gst_mode' => $mode->value,
            'gst_percent' => $gstPercent?->round(Decimal::PERCENT_SCALE)->toString(),
            'effective_rate' => $effective->toRate(),
            'internal_cost_rate_snapshot' => $internal->toRate(),
            'approved_boq_rate_snapshot' => $approved->toRate(),
            'variance_vs_internal' => $vsInternal->toRate(),
            'variance_vs_internal_percent' => self::percentVariance($vsInternal, $internal),
            'variance_vs_approved' => $vsApproved->toRate(),
            'variance_vs_approved_percent' => self::percentVariance($vsApproved, $approved),
        ];
    }

    /**
     * Mark lowest effective rate among active quotes. Ties keep all lowest flagged.
     *
     * @param  list<array{effective_rate?: string, is_active?: bool}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function markLowest(array $rows): array
    {
        $activeRates = [];
        foreach ($rows as $i => $row) {
            if (($row['is_active'] ?? true) === false) {
                continue;
            }
            $activeRates[$i] = Decimal::of($row['effective_rate'] ?? '0');
        }

        if ($activeRates === []) {
            return array_map(function (array $row) {
                $row['is_lowest'] = false;

                return $row;
            }, $rows);
        }

        $lowest = null;
        foreach ($activeRates as $rate) {
            if ($lowest === null || $rate->lessThan($lowest)) {
                $lowest = $rate;
            }
        }

        $out = [];
        foreach ($rows as $i => $row) {
            $row['is_lowest'] = isset($activeRates[$i]) && $activeRates[$i]->equals($lowest);
            $out[] = $row;
        }

        return $out;
    }

    private static function percentVariance(Decimal $delta, Decimal $base): ?string
    {
        if (! $base->isPositive()) {
            return null;
        }

        return $delta->times(100)->dividedBy($base, Decimal::PERCENT_SCALE)->round(Decimal::PERCENT_SCALE)->toString();
    }
}
