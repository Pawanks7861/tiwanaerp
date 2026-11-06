<?php

namespace App\Services\Boq;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqItemMaterialCost;
use App\Models\Masters\Material;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Direct per-BOQ-unit material costing for simplified BOQ (Qty × Rate). No norms/recipes/procurement.
 */
class SimpleBoqMaterialCostService
{
    /** @return list<array{material_name: string, quantity: string, unit: string, rate: string}> */
    public static function defaultRows(): array
    {
        return [
            ['material_name' => 'Cement', 'quantity' => '0', 'unit' => 'Bag', 'rate' => '0'],
            ['material_name' => 'Sand', 'quantity' => '0', 'unit' => 'Cum', 'rate' => '0'],
            ['material_name' => 'Aggregate', 'quantity' => '0', 'unit' => 'Cum', 'rate' => '0'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{material_id: ?int, material_name: string, quantity: string, unit: string, rate: string, amount: string, sequence: int}>
     */
    public function normalize(array $rows, string $errorPrefix = 'materials'): array
    {
        $normalized = [];

        foreach (array_values($rows) as $i => $row) {
            $key = "{$errorPrefix}.{$i}";
            $validator = Validator::make($row, [
                'material_id' => ['nullable', 'integer'],
                'material_name' => ['required', 'string', 'max:160'],
                'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
                'unit' => ['required', 'string', 'max:40'],
                'rate' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            ], [], [
                'material_name' => 'material name',
                'quantity' => 'quantity',
                'unit' => 'unit',
                'rate' => 'rate',
            ]);

            if ($validator->fails()) {
                $messages = [];
                foreach ($validator->errors()->messages() as $field => $fieldErrors) {
                    foreach ($fieldErrors as $message) {
                        $messages["{$key}.{$field}"] = [$message];
                    }
                }
                throw ValidationException::withMessages($messages);
            }

            $data = $validator->validated();
            $qty = Decimal::of((string) $data['quantity'])->toQuantity();
            $rate = Decimal::of((string) $data['rate'])->toRate();
            $amount = Decimal::of($qty)->times($rate)->toMoney();

            $normalized[] = [
                'material_id' => isset($data['material_id']) ? (int) $data['material_id'] : null,
                'material_name' => trim((string) $data['material_name']),
                'quantity' => $qty,
                'unit' => trim((string) $data['unit']),
                'rate' => $rate,
                'amount' => $amount,
                'sequence' => $i,
            ];
        }

        return $normalized;
    }

    /**
     * Replace all simple material cost rows for an item. Authoritative amount = Qty × Rate.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncForItem(Boq $boq, BoqItem $item, array $rows, ?User $user = null, string $errorPrefix = 'materials'): void
    {
        $boq->assertEditable();
        $normalized = $this->normalize($rows, $errorPrefix);

        if ($normalized !== []) {
            $materialIds = collect($normalized)->pluck('material_id')->filter()->unique()->values();
            if ($materialIds->isNotEmpty()) {
                $valid = Material::query()->whereIn('id', $materialIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
                foreach ($normalized as $i => $row) {
                    if ($row['material_id'] !== null && ! in_array($row['material_id'], $valid, true)) {
                        throw ValidationException::withMessages([
                            "{$errorPrefix}.{$i}.material_id" => 'Choose a material of this company.',
                        ]);
                    }
                }
            }
        }

        $item->simpleMaterialCosts()->delete();

        foreach ($normalized as $row) {
            $cost = new BoqItemMaterialCost;
            $cost->forceFill([
                'company_id' => $boq->company_id,
                'project_id' => $boq->project_id,
                'boq_id' => $boq->id,
                'boq_item_id' => $item->id,
                'material_id' => $row['material_id'],
                'material_name' => $row['material_name'],
                'quantity' => $row['quantity'],
                'unit' => $row['unit'],
                'rate' => $row['rate'],
                'amount' => $row['amount'],
                'sequence' => $row['sequence'],
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ])->save();
        }

        $item->forceFill(['simple_materials_initialized' => true])->saveQuietly();
    }

    /**
     * Simplified BOQ: at least one row with quantity > 0 and rate >= 0 drives the item rate.
     * Zero-quantity placeholders do not.
     *
     * @param  iterable<BoqItemMaterialCost|array<string, mixed>>  $rows
     */
    public function drivesItemRate(iterable $rows): bool
    {
        foreach ($rows as $row) {
            $qty = is_array($row) ? ($row['quantity'] ?? '0') : ($row->quantity ?? '0');
            $rate = is_array($row) ? ($row['rate'] ?? '0') : ($row->rate ?? '0');
            $qty = Decimal::of((string) $qty);
            $rate = Decimal::of((string) $rate);
            if ($qty->isPositive() && ! $rate->isNegative()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authoritative per-row metrics. Totals never trust a frontend-submitted amount/total qty.
     *
     * @param  array{quantity?: mixed, rate?: mixed}  $row
     * @return array{amount: string, total_quantity: string, total_cost: string}
     */
    public function lineMetrics(array $row, string $boqQuantity): array
    {
        $qty = Decimal::of((string) ($row['quantity'] ?? '0'))->toQuantity();
        $rate = Decimal::of((string) ($row['rate'] ?? '0'))->toRate();
        $boqQty = Decimal::of($boqQuantity)->toQuantity();
        $totalQty = Decimal::of($qty)->times($boqQty)->toQuantity();

        return [
            'amount' => Decimal::of($qty)->times($rate)->toMoney(),
            'total_quantity' => $totalQty,
            'total_cost' => Decimal::of($totalQty)->times($rate)->toMoney(),
        ];
    }

    /**
     * @param  list<BoqItemMaterialCost>|iterable<BoqItemMaterialCost|array<string, mixed>>  $rows
     * @return array{per_unit: string, estimated_total: string, quantity_summary: list<array{material_name: string, unit: string, per_unit_quantity: string, total_quantity: string}>}
     */
    public function totals(iterable $rows, string $boqQuantity): array
    {
        $perUnit = Decimal::zero();
        $aggregated = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->material_name ?? $row['material_name'] ?? ''));
            $unit = trim((string) ($row->unit ?? $row['unit'] ?? ''));
            $qty = Decimal::of((string) ($row->quantity ?? $row['quantity'] ?? '0'));
            $rate = Decimal::of((string) ($row->rate ?? $row['rate'] ?? '0'));
            $perUnit = $perUnit->plus($qty->times($rate));

            if ($name === '' || $unit === '') {
                continue;
            }

            $key = mb_strtolower($name).'|'.mb_strtolower($unit);
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'material_name' => $name,
                    'unit' => $unit,
                    'per_unit_quantity' => Decimal::zero(),
                ];
            }
            $aggregated[$key]['per_unit_quantity'] = $aggregated[$key]['per_unit_quantity']->plus($qty);
        }

        $boqQty = Decimal::of($boqQuantity)->toQuantity();
        $quantitySummary = [];
        foreach ($aggregated as $group) {
            $perUnitQty = $group['per_unit_quantity']->toQuantity();
            $quantitySummary[] = [
                'material_name' => $group['material_name'],
                'unit' => $group['unit'],
                'per_unit_quantity' => $perUnitQty,
                'total_quantity' => Decimal::of($perUnitQty)->times($boqQty)->toQuantity(),
            ];
        }

        $perUnitMoney = $perUnit->toMoney();
        $estimated = Decimal::of($perUnitMoney)->times($boqQty)->toMoney();

        return [
            'per_unit' => $perUnitMoney,
            'estimated_total' => $estimated,
            'quantity_summary' => $quantitySummary,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloadForItem(BoqItem $item): array
    {
        return $item->simpleMaterialCosts()
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (BoqItemMaterialCost $row) => [
                'id' => $row->id,
                'material_id' => $row->material_id,
                'material_name' => $row->material_name,
                'quantity' => (string) $row->quantity,
                'unit' => $row->unit,
                'rate' => (string) $row->rate,
                'amount' => (string) $row->amount,
                'sequence' => $row->sequence,
            ])
            ->all();
    }
}
