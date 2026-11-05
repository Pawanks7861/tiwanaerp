<?php

namespace App\Services\Boq;

use App\Enums\Boq\BbsShapeCode;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative BBS maths (Stage 5). Planning only.
 *
 * Unit weight (kg/m) = d² / 162  (d in mm) — Indian standard bar-weight formula.
 * Dimensions A–E, hook, bend deduction are in meters.
 * Cutting length formulas are whitelisted (no eval).
 */
class BbsCalculator
{
    public const MAX_WASTAGE_PERCENT = '100';

    public const MAX_DIAMETER_MM = 64;

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   number_of_members: int,
     *   bars_per_member: int,
     *   total_bars: int,
     *   cutting_length: string,
     *   total_length: string,
     *   unit_weight: string,
     *   theoretical_weight: string
     * }
     */
    public static function line(array $input): array
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
        $barsPer = max(0, (int) ($input['bars_per_member'] ?? 0));
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

        $cutting = self::cuttingLength($shape, $dims);
        if ($cutting->isNegative()) {
            throw ValidationException::withMessages(['cutting_length' => 'Cutting length cannot be negative. Check bend deduction.']);
        }

        $unitWeight = self::unitWeight($diameter);
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
        ];
    }

    /** Unit weight kg/m = d² / 162 (HALF_UP to rate scale). */
    public static function unitWeight(int $diameterMm): Decimal
    {
        $d = Decimal::of((string) $diameterMm);

        return $d->times($d)->dividedBy(162, Decimal::RATE_SCALE)->round(Decimal::RATE_SCALE);
    }

    /**
     * @param  iterable<string|null>  $theoreticalWeightsKg
     * @return array{total_theoretical_weight: string, wastage_weight: string, required_weight: string, wastage_percent: string}
     */
    public static function headerTotals(iterable $theoreticalWeightsKg, string $wastagePercent): array
    {
        $theo = Decimal::sum($theoreticalWeightsKg)->round(Decimal::QTY_SCALE);
        $wastage = Decimal::of($wastagePercent ?? '0')->round(Decimal::PERCENT_SCALE);
        if ($wastage->isNegative() || $wastage->greaterThan(self::MAX_WASTAGE_PERCENT)) {
            throw ValidationException::withMessages(['wastage_percent' => 'Wastage must be between 0 and 100.']);
        }
        $wasteQty = $theo->times($wastage)->dividedBy(100, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
        $required = $theo->plus($wasteQty)->round(Decimal::QTY_SCALE);

        return [
            'total_theoretical_weight' => $theo->toQuantity(),
            'wastage_weight' => $wasteQty->toQuantity(),
            'required_weight' => $required->toQuantity(),
            'wastage_percent' => $wastage->toString(),
        ];
    }

    /**
     * Allocate header wastage proportionally by theoretical weight across diameter groups.
     *
     * @param  array<int|string, string>  $theoByDia  diameter => theoretical kg
     * @return list<array{diameter_mm: int, total_length: string, theoretical_weight: string, wastage_weight: string, required_weight: string}>
     */
    public static function diameterSummary(array $theoByDia, array $lengthByDia, string $wastagePercent): array
    {
        $header = self::headerTotals(array_values($theoByDia), $wastagePercent);
        $totalTheo = Decimal::of($header['total_theoretical_weight']);
        $totalWaste = Decimal::of($header['wastage_weight']);

        $out = [];
        foreach ($theoByDia as $dia => $theo) {
            $t = Decimal::of($theo);
            $share = $totalTheo->isPositive()
                ? $totalWaste->times($t)->dividedBy($totalTheo, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE)
                : Decimal::zero();
            $out[] = [
                'diameter_mm' => (int) $dia,
                'total_length' => Decimal::of($lengthByDia[$dia] ?? '0')->toQuantity(),
                'theoretical_weight' => $t->toQuantity(),
                'wastage_weight' => $share->toQuantity(),
                'required_weight' => $t->plus($share)->toQuantity(),
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
