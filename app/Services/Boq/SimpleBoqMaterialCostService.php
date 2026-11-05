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
     * @param  list<BoqItemMaterialCost>|iterable<BoqItemMaterialCost>  $rows
     * @return array{per_unit: string, estimated_total: string}
     */
    public function totals(iterable $rows, string $boqQuantity): array
    {
        $perUnit = Decimal::zero();
        foreach ($rows as $row) {
            $qty = Decimal::of((string) ($row->quantity ?? $row['quantity'] ?? '0'));
            $rate = Decimal::of((string) ($row->rate ?? $row['rate'] ?? '0'));
            $perUnit = $perUnit->plus($qty->times($rate));
        }

        $perUnitMoney = $perUnit->toMoney();
        $estimated = Decimal::of($perUnitMoney)->times(Decimal::of($boqQuantity)->toQuantity())->toMoney();

        return [
            'per_unit' => $perUnitMoney,
            'estimated_total' => $estimated,
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
