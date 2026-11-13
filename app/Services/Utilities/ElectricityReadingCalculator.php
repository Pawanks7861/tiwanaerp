<?php

namespace App\Services\Utilities;

use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Server-authoritative meter reading formulas (kWh / kVAh / demand / PF).
 */
final class ElectricityReadingCalculator
{
    /**
     * @return array{
     *   days: int,
     *   kwh_consumption: string,
     *   kvah_consumption: ?string,
     *   billing_demand: ?string,
     *   power_factor: ?string
     * }
     */
    public function compute(array $data): array
    {
        $from = (string) ($data['from_date'] ?? '');
        $to = (string) ($data['to_date'] ?? '');
        if ($from === '' || $to === '' || $to < $from) {
            throw ValidationException::withMessages(['to_date' => 'The to date must be on or after the from date.']);
        }

        $mf = Decimal::of($data['multiplication_factor'] ?? '1');
        if ($mf->lessThanOrEqual('0')) {
            throw ValidationException::withMessages(['multiplication_factor' => 'The multiplication factor must be greater than zero.']);
        }

        $openingKwh = Decimal::of($data['opening_kwh'] ?? '0');
        $closingKwh = Decimal::of($data['closing_kwh'] ?? '0');
        if ($closingKwh->lessThan($openingKwh)) {
            throw ValidationException::withMessages(['closing_kwh' => 'Closing kWh cannot be less than opening kWh.']);
        }

        $kwh = $closingKwh->minus($openingKwh)->times($mf)->toQuantity();

        $kvah = null;
        $pf = null;
        if (isset($data['opening_kvah'], $data['closing_kvah']) && $data['opening_kvah'] !== null && $data['closing_kvah'] !== null && $data['opening_kvah'] !== '' && $data['closing_kvah'] !== '') {
            $openingKvah = Decimal::of($data['opening_kvah']);
            $closingKvah = Decimal::of($data['closing_kvah']);
            if ($closingKvah->lessThan($openingKvah)) {
                throw ValidationException::withMessages(['closing_kvah' => 'Closing kVAh cannot be less than opening kVAh.']);
            }
            $kvahQty = $closingKvah->minus($openingKvah)->times($mf);
            $kvah = $kvahQty->toQuantity();
            if (! $kvahQty->isZero()) {
                $pf = Decimal::of($kwh)->dividedBy($kvahQty, 6)->round(6)->toString();
            }
        }

        $demand = null;
        if (isset($data['mdi_reading']) && $data['mdi_reading'] !== null && $data['mdi_reading'] !== '') {
            $demand = Decimal::of($data['mdi_reading'])->times($mf)->toQuantity();
        }

        $days = (int) \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1;

        return [
            'days' => max(1, $days),
            'kwh_consumption' => $kwh,
            'kvah_consumption' => $kvah,
            'billing_demand' => $demand,
            'power_factor' => $pf,
        ];
    }
}
