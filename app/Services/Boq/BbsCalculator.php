<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsBarCountRounding;
use App\Enums\Boq\BbsCountMode;
use App\Enums\Boq\BbsLapMode;
use App\Enums\Boq\BbsShapeCode;
use App\Enums\Boq\SteelUnitWeightMethod;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative BBS maths (Stage 5 + Stage 9A). Planning only.
 *
 * Unit weight (kg/m) = d² / 162  (d in mm) — default Indian standard.
 * Stage 9A: spacing count, lap diameter factor, profile weights, adjustments before wastage.
 */
class BbsCalculator
{
    public const MAX_WASTAGE_PERCENT = '100';

    public const MAX_DIAMETER_MM = 64;

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>|null  $profileWeights  diameter_mm => kg/m
     * @return array{
     *   number_of_members: int,
     *   bars_per_member: int,
     *   total_bars: int,
     *   cutting_length: string,
     *   total_length: string,
     *   unit_weight: string,
     *   theoretical_weight: string,
     *   lap_length: string,
     *   count_mode: string,
     *   lap_mode: string
     * }
     */
    public static function line(array $input, ?array $profileWeights = null): array
    {
        $shape = $input['shape_code'] instanceof BbsShapeCode
            ? $input['shape_code']
            : BbsShapeCode::tryFrom((string) ($input['shape_code'] ?? ''))
                ?? throw ValidationException::withMessages(['shape_code' => 'Unsupported BBS shape.']);

        $diameter = (int) ($input['diameter_mm'] ?? 0);
        if ($diameter < 1 || $diameter > self::MAX_DIAMETER_MM) {
            throw ValidationException::withMessages(['diameter_mm' => 'Diameter must be between 1 and '.self::MAX_DIAMETER_MM.' mm.']);
        }

        $members = max(0, (int) ($input['number_of_members'] ?? 0));
        $countMode = BbsCountMode::tryFrom((string) ($input['count_mode'] ?? BbsCountMode::Manual->value))
            ?? BbsCountMode::Manual;

        if ($countMode === BbsCountMode::SpacingBased) {
            $barsPer = self::spacingBarCount($input);
        } else {
            $barsPer = max(0, (int) ($input['bars_per_member'] ?? 0));
        }

        $totalBars = $members * $barsPer;

        $dims = [
            'a_length' => self::dim($input['a_length'] ?? null),
            'b_length' => self::dim($input['b_length'] ?? null),
            'c_length' => self::dim($input['c_length'] ?? null),
            'd_length' => self::dim($input['d_length'] ?? null),
            'e_length' => self::dim($input['e_length'] ?? null),
            'hook_length' => self::dim($input['hook_length'] ?? '0'),
            'bend_deduction' => self::dim($input['bend_deduction'] ?? '0'),
        ];

        foreach ($shape->requiredDimensions() as $key) {
            if ($dims[$key]->lessThanOrEqual('0')) {
                throw ValidationException::withMessages([$key => 'Required dimension for '.$shape->label().'.']);
            }
        }

        if ($dims['hook_length']->isNegative() || $dims['bend_deduction']->isNegative()) {
            throw ValidationException::withMessages(['hook_length' => 'Hook and bend deduction cannot be negative.']);
        }

        $lap = self::resolveLap($input, $diameter);
        $cutting = self::cuttingLength($shape, $dims)->plus(
            Decimal::of($lap['lap_length'])->times(Decimal::of($lap['lap_count_per_bar']))
        )->round(Decimal::QTY_SCALE);

        if ($cutting->isNegative()) {
            throw ValidationException::withMessages(['cutting_length' => 'Cutting length cannot be negative. Check bend deduction.']);
        }

        $unitWeight = self::resolveUnitWeight($input, $diameter, $profileWeights);
        $totalLength = Decimal::of((string) $totalBars)->times($cutting)->round(Decimal::QTY_SCALE);
        $theo = $totalLength->times($unitWeight)->round(Decimal::QTY_SCALE);

        return [
            'number_of_members' => $members,
            'bars_per_member' => $barsPer,
            'total_bars' => $totalBars,
            'cutting_length' => $cutting->toQuantity(),
            'total_length' => $totalLength->toQuantity(),
            'unit_weight' => $unitWeight->toRate(),
            'theoretical_weight' => $theo->toQuantity(),
            'lap_length' => $lap['lap_length'],
            'lap_count_per_bar' => $lap['lap_count_per_bar'],
            'count_mode' => $countMode->value,
            'lap_mode' => $lap['lap_mode'],
        ];
    }

    /**
     * Clear length / spacing (+ optional end bar), then faces × members applied by caller via bars_per_member result.
     *
     * @param  array<string, mixed>  $input
     */
    public static function spacingBarCount(array $input): int
    {
        $distribution = Decimal::of($input['distribution_length'] ?? '0')->round(Decimal::QTY_SCALE);
        $start = Decimal::of($input['start_cover'] ?? '0')->round(Decimal::QTY_SCALE);
        $end = Decimal::of($input['end_cover'] ?? '0')->round(Decimal::QTY_SCALE);
        $spacing = Decimal::of($input['spacing'] ?? '0')->round(Decimal::QTY_SCALE);
        $includeEnd = filter_var($input['include_end_bar'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $faces = max(1, (int) ($input['faces_layers_multiplier'] ?? 1));

        if ($distribution->isNegative() || $start->isNegative() || $end->isNegative()) {
            throw ValidationException::withMessages(['distribution_length' => 'Distribution length and covers cannot be negative.']);
        }
        if (! $spacing->isPositive()) {
            throw ValidationException::withMessages(['spacing' => 'Spacing must be positive.']);
        }

        $clear = $distribution->minus($start)->minus($end);
        if ($clear->isNegative()) {
            throw ValidationException::withMessages(['distribution_length' => 'Covers exceed distribution length.']);
        }

        $raw = $clear->dividedBy($spacing, 10);
        if ($includeEnd) {
            $raw = $raw->plus('1');
        }

        $rounding = BbsBarCountRounding::tryFrom((string) ($input['bar_count_rounding'] ?? BbsBarCountRounding::Ceil->value))
            ?? BbsBarCountRounding::Ceil;

        $base = self::applyBarRounding($raw, $rounding);
        $total = $base * $faces;

        return max(0, $total);
    }

    public static function lapLengthFromDiameter(int $diameterMm, string $factor): string
    {
        if ($diameterMm < 1) {
            throw ValidationException::withMessages(['diameter_mm' => 'Invalid diameter for lap.']);
        }
        $f = Decimal::of($factor)->round(Decimal::RATE_SCALE);
        if (! $f->isPositive()) {
            throw ValidationException::withMessages(['lap_diameter_factor' => 'Lap diameter factor must be positive.']);
        }

        return Decimal::of((string) $diameterMm)
            ->times($f)
            ->dividedBy(1000, Decimal::QTY_SCALE)
            ->round(Decimal::QTY_SCALE)
            ->toQuantity();
    }

    /** Unit weight kg/m = d² / 162 (HALF_UP to rate scale). */
    public static function unitWeight(int $diameterMm): Decimal
    {
        $d = Decimal::of((string) $diameterMm);

        return $d->times($d)->dividedBy(162, Decimal::RATE_SCALE)->round(Decimal::RATE_SCALE);
    }

    /**
     * Theoretical + adjustments → adjusted; then wastage on adjusted; then required.
     *
     * @param  iterable<string|null>  $theoreticalWeightsKg
     * @param  iterable<string|null>  $adjustmentWeightsKg
     * @return array{
     *   total_theoretical_weight: string,
     *   adjusted_theoretical_weight: string,
     *   wastage_weight: string,
     *   required_weight: string,
     *   wastage_percent: string
     * }
     */
    public static function headerTotals(
        iterable $theoreticalWeightsKg,
        string $wastagePercent,
        iterable $adjustmentWeightsKg = [],
    ): array {
        $theo = Decimal::sum($theoreticalWeightsKg)->round(Decimal::QTY_SCALE);
        $adj = Decimal::sum($adjustmentWeightsKg)->round(Decimal::QTY_SCALE);
        $adjusted = $theo->plus($adj)->round(Decimal::QTY_SCALE);
        if ($adjusted->isNegative()) {
            throw ValidationException::withMessages([
                'adjustment_qty_kg' => 'Adjusted theoretical weight cannot be below zero.',
            ]);
        }

        $wastage = Decimal::of($wastagePercent ?? '0')->round(Decimal::PERCENT_SCALE);
        if ($wastage->isNegative() || $wastage->greaterThan(self::MAX_WASTAGE_PERCENT)) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage must be between 0 and 100.']);
        }
        $wasteQty = $adjusted->times($wastage)->dividedBy(100, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
        $required = $adjusted->plus($wasteQty)->round(Decimal::QTY_SCALE);

        return [
            'total_theoretical_weight' => $theo->toQuantity(),
            'adjusted_theoretical_weight' => $adjusted->toQuantity(),
            'wastage_weight' => $wasteQty->toQuantity(),
            'required_weight' => $required->toQuantity(),
            'wastage_percent' => $wastage->toString(),
        ];
    }

    /**
     * @param  array<int|string, string>  $theoByDia
     * @param  array<int|string, string>  $adjByDia
     * @return list<array{diameter_mm: int, total_length: string, theoretical_weight: string, adjustment_weight: string, adjusted_theoretical_weight: string, wastage_weight: string, required_weight: string}>
     */
    public static function diameterSummary(
        array $theoByDia,
        array $lengthByDia,
        string $wastagePercent,
        array $adjByDia = [],
    ): array {
        $allDia = array_unique(array_merge(array_keys($theoByDia), array_keys($adjByDia)));
        $theoValues = [];
        $adjValues = [];
        foreach ($allDia as $dia) {
            $theoValues[] = $theoByDia[$dia] ?? '0';
            $adjValues[] = $adjByDia[$dia] ?? '0';
        }

        $header = self::headerTotals($theoValues, $wastagePercent, $adjValues);
        $totalAdjusted = Decimal::of($header['adjusted_theoretical_weight']);
        $totalWaste = Decimal::of($header['wastage_weight']);

        $out = [];
        foreach ($allDia as $dia) {
            $t = Decimal::of($theoByDia[$dia] ?? '0');
            $a = Decimal::of($adjByDia[$dia] ?? '0');
            $adjTheo = $t->plus($a)->round(Decimal::QTY_SCALE);
            if ($adjTheo->isNegative()) {
                throw ValidationException::withMessages([
                    'adjustment_qty_kg' => 'Adjusted theoretical for diameter '.$dia.' mm cannot be below zero.',
                ]);
            }
            $share = $totalAdjusted->isPositive()
                ? $totalWaste->times($adjTheo)->dividedBy($totalAdjusted, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE)
                : Decimal::zero();
            $out[] = [
                'diameter_mm' => (int) $dia,
                'total_length' => Decimal::of($lengthByDia[$dia] ?? '0')->toQuantity(),
                'theoretical_weight' => $t->toQuantity(),
                'adjustment_weight' => $a->toQuantity(),
                'adjusted_theoretical_weight' => $adjTheo->toQuantity(),
                'wastage_weight' => $share->toQuantity(),
                'required_weight' => $adjTheo->plus($share)->toQuantity(),
            ];
        }

        usort($out, fn ($a, $b) => $a['diameter_mm'] <=> $b['diameter_mm']);

        return $out;
    }

    /**
     * @param  array{a_length: Decimal, b_length: Decimal, c_length: Decimal, d_length: Decimal, e_length: Decimal, hook_length: Decimal, bend_deduction: Decimal}  $dims
     */
    private static function cuttingLength(BbsShapeCode $shape, array $dims): Decimal
    {
        $raw = match ($shape) {
            BbsShapeCode::Straight => $dims['a_length']->plus($dims['hook_length']),
            BbsShapeCode::LBar => $dims['a_length']->plus($dims['b_length'])->plus($dims['hook_length']),
            BbsShapeCode::UBar => $dims['a_length']->plus($dims['b_length']->times(2))->plus($dims['hook_length']),
            BbsShapeCode::Stirrup => $dims['a_length']->plus($dims['b_length'])->times(2)->plus($dims['hook_length']),
            BbsShapeCode::Cranked => $dims['a_length']
                ->plus($dims['b_length'])
                ->plus($dims['c_length'])
                ->plus($dims['d_length'])
                ->plus($dims['hook_length']),
            BbsShapeCode::CustomWhitelisted => $dims['a_length']
                ->plus($dims['b_length'])
                ->plus($dims['c_length'])
                ->plus($dims['d_length'])
                ->plus($dims['e_length'])
                ->plus($dims['hook_length']),
        };

        return $raw->minus($dims['bend_deduction'])->round(Decimal::QTY_SCALE);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lap_mode: string, lap_length: string, lap_count_per_bar: string}
     */
    private static function resolveLap(array $input, int $diameter): array
    {
        $mode = BbsLapMode::tryFrom((string) ($input['lap_mode'] ?? BbsLapMode::Manual->value))
            ?? BbsLapMode::Manual;
        $count = Decimal::of($input['lap_count_per_bar'] ?? '0')->round(Decimal::QTY_SCALE);
        if ($count->isNegative()) {
            throw ValidationException::withMessages(['lap_count_per_bar' => 'Lap count cannot be negative.']);
        }

        if ($mode === BbsLapMode::DiameterFactor) {
            $factor = (string) ($input['lap_diameter_factor'] ?? '');
            $length = self::lapLengthFromDiameter($diameter, $factor);
        } else {
            $length = self::dim($input['lap_length'] ?? '0')->toQuantity();
        }

        return [
            'lap_mode' => $mode->value,
            'lap_length' => $length,
            'lap_count_per_bar' => $count->toQuantity(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>|null  $profileWeights
     */
    private static function resolveUnitWeight(array $input, int $diameter, ?array $profileWeights): Decimal
    {
        if (isset($input['unit_weight_override']) && $input['unit_weight_override'] !== null && $input['unit_weight_override'] !== '') {
            $reason = trim((string) ($input['unit_weight_override_reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'unit_weight_override_reason' => 'Override reason is required when replacing unit weight.',
                ]);
            }
            $override = Decimal::of((string) $input['unit_weight_override'])->round(Decimal::RATE_SCALE);
            if (! $override->isPositive()) {
                throw ValidationException::withMessages(['unit_weight_override' => 'Override unit weight must be positive.']);
            }

            return $override;
        }

        $method = SteelUnitWeightMethod::tryFrom((string) ($input['unit_weight_method'] ?? SteelUnitWeightMethod::FormulaD2162->value))
            ?? SteelUnitWeightMethod::FormulaD2162;

        if ($method === SteelUnitWeightMethod::Profile) {
            if ($profileWeights === null || ! array_key_exists($diameter, $profileWeights)) {
                throw ValidationException::withMessages([
                    'unit_weight' => 'Unit weight not configured for this diameter.',
                ]);
            }

            return Decimal::of($profileWeights[$diameter])->round(Decimal::RATE_SCALE);
        }

        return self::unitWeight($diameter);
    }

    private static function applyBarRounding(Decimal $raw, BbsBarCountRounding $rule): int
    {
        return match ($rule) {
            BbsBarCountRounding::Ceil => self::ceilToInt($raw),
            BbsBarCountRounding::Floor => self::floorToInt($raw),
            BbsBarCountRounding::Round => (int) $raw->round(0)->toString(),
            BbsBarCountRounding::ExactIfInteger => self::exactIntegerBars($raw),
        };
    }

    private static function floorToInt(Decimal $raw): int
    {
        $scaled = $raw->round(Decimal::QTY_SCALE);
        $truncated = (int) $scaled->toString();
        if ($scaled->lessThan('0') && ! Decimal::of((string) $truncated)->equals($scaled)) {
            return $truncated - 1;
        }

        return Decimal::of((string) $truncated)->greaterThan($scaled) ? $truncated - 1 : $truncated;
    }

    private static function ceilToInt(Decimal $raw): int
    {
        $floor = self::floorToInt($raw);
        if (Decimal::of((string) $floor)->equals($raw->round(Decimal::QTY_SCALE))) {
            return $floor;
        }

        return $floor + 1;
    }

    private static function exactIntegerBars(Decimal $raw): int
    {
        $scaled = $raw->round(Decimal::QTY_SCALE);
        $asInt = self::floorToInt($scaled);
        if (! Decimal::of((string) $asInt)->equals($scaled)) {
            throw ValidationException::withMessages([
                'spacing' => 'Bar count is not an integer under exact_if_integer rounding. Adjust spacing/covers or choose another rounding rule.',
            ]);
        }

        return $asInt;
    }

    private static function dim(mixed $value): Decimal
    {
        if ($value === null || $value === '') {
            return Decimal::zero();
        }

        $d = Decimal::of((string) $value)->round(Decimal::QTY_SCALE);
        if ($d->isNegative()) {
            throw ValidationException::withMessages(['dimension' => 'Dimensions cannot be negative.']);
        }

        return $d;
    }
}
