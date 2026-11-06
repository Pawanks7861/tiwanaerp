<?php

namespace App\Services\Weighbridge;

use App\Enums\Weighbridge\WeighbridgeSource;
use App\Models\Weighbridge\WeighbridgeReceipt;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * One truck-scale receipt per parent. Net weight is Gross − Tare when both are given.
 * A slip that only states net weight is stored with a reason and is not overwritten.
 */
class WeighbridgeReceiptService
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'weighbridge' => ['nullable', 'array'],
            'weighbridge.name' => ['nullable', 'string', 'max:120'],
            'weighbridge.slip_no' => ['nullable', 'string', 'max:60'],
            'weighbridge.weighed_at' => ['nullable', 'date'],
            'weighbridge.vehicle_no' => ['nullable', 'string', 'max:30'],
            'weighbridge.gross_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'weighbridge.tare_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'weighbridge.net_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'weighbridge.weight_unit' => ['nullable', 'string', 'max:10'],
            'weighbridge.manual_reason' => ['nullable', 'string', 'max:300'],
            'weighbridge.operator_name' => ['nullable', 'string', 'max:120'],
            'weighbridge.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function sync(Model $parent, ?array $data): ?WeighbridgeReceipt
    {
        $existing = WeighbridgeReceipt::query()
            ->where('weighable_type', $parent->getMorphClass())
            ->where('weighable_id', $parent->getKey())
            ->first();

        if ($data === null || $this->blank($data)) {
            $existing?->delete();

            return null;
        }

        $values = $this->values($data);
        if ($existing) {
            $existing->forceFill($values)->save();

            return $existing;
        }

        $receipt = new WeighbridgeReceipt;
        $receipt->forceFill([
            ...$values,
            'weighable_type' => $parent->getMorphClass(),
            'weighable_id' => $parent->getKey(),
        ])->save();

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function blank(array $data): bool
    {
        foreach (['name', 'slip_no', 'weighed_at', 'vehicle_no', 'gross_weight', 'tare_weight', 'net_weight', 'operator_name', 'remarks', 'manual_reason'] as $key) {
            if (! blank($data[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $gross = $this->weight($data['gross_weight'] ?? null, 'weighbridge.gross_weight');
        $tare = $this->weight($data['tare_weight'] ?? null, 'weighbridge.tare_weight');
        $manual = $this->weight($data['net_weight'] ?? null, 'weighbridge.net_weight');

        if ($gross !== null && $tare !== null) {
            if ($tare->greaterThan($gross)) {
                throw ValidationException::withMessages(['weighbridge.tare_weight' => 'Tare weight cannot exceed gross weight.']);
            }
            $net = $gross->minus($tare);
            $source = WeighbridgeSource::Measured;
            $reason = null;
        } elseif ($manual !== null) {
            if (blank($data['manual_reason'] ?? null)) {
                throw ValidationException::withMessages(['weighbridge.manual_reason' => 'Say where the net weight came from when gross and tare are not both entered.']);
            }
            $net = $manual;
            $gross = null;
            $tare = null;
            $source = WeighbridgeSource::Manual;
            $reason = $data['manual_reason'];
        } else {
            throw ValidationException::withMessages(['weighbridge.net_weight' => 'Enter gross and tare, or a net weight.']);
        }

        return [
            'name' => $data['name'] ?? null,
            'slip_no' => $data['slip_no'] ?? null,
            'weighed_at' => $data['weighed_at'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'gross_weight' => $gross?->toQuantity(),
            'tare_weight' => $tare?->toQuantity(),
            'net_weight' => $net->toQuantity(),
            'weight_unit' => $data['weight_unit'] ?? 'kg',
            'source' => $source,
            'manual_reason' => $reason,
            'operator_name' => $data['operator_name'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function weight(mixed $value, string $key): ?Decimal
    {
        if ($value === null || $value === '') {
            return null;
        }
        $weight = Decimal::of((string) $value);
        if ($weight->isNegative()) {
            throw ValidationException::withMessages([$key => 'Weight cannot be negative.']);
        }

        return $weight;
    }
}
