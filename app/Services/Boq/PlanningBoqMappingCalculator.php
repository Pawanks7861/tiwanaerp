<?php

namespace App\Services\Boq;

use App\Enums\Boq\PlanningBoqMappingMethod;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Optional Planning ↔ BOQ quantity mapping. Units need not match.
 *
 * Conversion based:
 *   conversion_factor = allocated_boq_qty / planning_qty  (when planning_qty > 0)
 * Example: 62.40 Cum / 104 Nos = 0.6000 Cum per footing.
 */
class PlanningBoqMappingCalculator
{
    /**
     * @param  array{
     *   mapping_method?: string|null,
     *   allocated_boq_qty?: string|null,
     *   planning_qty?: string|null,
     *   conversion_factor?: string|null,
     *   boq_unit_symbol?: string|null,
     *   planning_unit_symbol?: string|null,
     * }  $input
     * @return array{
     *   mapping_method: string,
     *   allocated_boq_qty: string,
     *   planning_qty: string,
     *   conversion_factor: string|null,
     *   units_compatible_warning: bool
     * }
     */
    public static function line(array $input): array
    {
        $method = PlanningBoqMappingMethod::tryFrom((string) ($input['mapping_method'] ?? PlanningBoqMappingMethod::ConversionBased->value))
            ?? throw ValidationException::withMessages(['mapping_method' => 'Choose a supported mapping method.']);

        $boqQty = Decimal::of($input['allocated_boq_qty'] ?? '0')->round(Decimal::QTY_SCALE);
        $planQty = Decimal::of($input['planning_qty'] ?? '0')->round(Decimal::QTY_SCALE);

        if ($boqQty->isNegative() || $planQty->isNegative()) {
            throw ValidationException::withMessages(['qty' => 'Mapped quantities cannot be negative.']);
        }

        $factor = null;
        if ($method === PlanningBoqMappingMethod::ConversionBased) {
            if (array_key_exists('conversion_factor', $input)) {
                // Explicit null/empty = no Nos-derived conversion (BOQ Quantity-only items).
                if ($input['conversion_factor'] !== null && $input['conversion_factor'] !== '') {
                    $factor = Decimal::of($input['conversion_factor'])->round(Decimal::QTY_SCALE);
                    if ($factor->isNegative()) {
                        throw ValidationException::withMessages(['conversion_factor' => 'Conversion factor cannot be negative.']);
                    }
                }
            } elseif ($planQty->isPositive()) {
                $factor = $boqQty->dividedBy($planQty, Decimal::QTY_SCALE)->round(Decimal::QTY_SCALE);
            } else {
                throw ValidationException::withMessages([
                    'planning_qty' => 'Planning quantity must be greater than zero for conversion-based mapping.',
                ]);
            }
        }

        $boqUnit = trim((string) ($input['boq_unit_symbol'] ?? ''));
        $planUnit = trim((string) ($input['planning_unit_symbol'] ?? ''));
        $warning = $boqUnit !== '' && $planUnit !== '' && strcasecmp($boqUnit, $planUnit) !== 0;

        return [
            'mapping_method' => $method->value,
            'allocated_boq_qty' => $boqQty->toQuantity(),
            'planning_qty' => $planQty->toQuantity(),
            'conversion_factor' => $factor?->toQuantity(),
            'units_compatible_warning' => $warning,
        ];
    }
}
