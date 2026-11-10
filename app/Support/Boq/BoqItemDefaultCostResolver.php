<?php

namespace App\Support\Boq;

use App\Models\Boq\BoqItem;

/**
 * Centralized default cost build-up for simplified BOQ items (e.g. PCC Work).
 * Never treat RCC as PCC.
 */
final class BoqItemDefaultCostResolver
{
    /**
     * @param  BoqItem|array{name?: mixed, description?: mixed, item_code?: mixed}|null  $item
     */
    public function isPccWork(BoqItem|array|null $item): bool
    {
        if ($item === null) {
            return false;
        }

        $name = is_array($item) ? (string) ($item['name'] ?? '') : (string) ($item->name ?? '');
        $description = is_array($item) ? (string) ($item['description'] ?? '') : (string) ($item->description ?? '');
        $code = is_array($item) ? (string) ($item['item_code'] ?? '') : (string) ($item->item_code ?? '');

        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name.' '.$description.' '.$code) ?? ''));
        if ($text === '') {
            return false;
        }

        // RCC / reinforced concrete must never receive PCC defaults.
        if (preg_match('/\brcc\b/u', $text) === 1
            || str_contains($text, 'reinforced cement concrete')
            || str_contains($text, 'reinforced concrete')) {
            return false;
        }

        if (preg_match('/\bpcc\b/u', $text) === 1) {
            return true;
        }

        return str_contains($text, 'plain cement concrete');
    }

    /**
     * PCC material defaults per 1 BOQ unit. Authoritative quantities/rates as strings.
     *
     * @return list<array{material_name: string, quantity: string, unit: string, rate: string}>
     */
    public function pccMaterialDefaults(): array
    {
        return [
            ['material_name' => 'Cement', 'quantity' => '3.4100', 'unit' => 'Bag', 'rate' => '380.0000'],
            ['material_name' => 'Sand', 'quantity' => '0.4740', 'unit' => 'Cum', 'rate' => '1690.8680'],
            ['material_name' => 'Aggregate', 'quantity' => '0.9480', 'unit' => 'Cum', 'rate' => '3191.9190'],
        ];
    }

    /**
     * @param  BoqItem|array{name?: mixed, description?: mixed, item_code?: mixed}|null  $item
     * @return list<array{material_name: string, quantity: string, unit: string, rate: string}>
     */
    public function materialDefaultsFor(BoqItem|array|null $item): array
    {
        if ($this->isPccWork($item)) {
            return $this->pccMaterialDefaults();
        }

        return \App\Services\Boq\SimpleBoqMaterialCostService::genericDefaultRows();
    }

    /**
     * Optional future hook for configured PCC labour defaults. Currently always 0.
     */
    public function defaultLabourRate(BoqItem|array|null $item): string
    {
        if (! $this->isPccWork($item)) {
            return '0.0000';
        }

        return '0.0000';
    }
}
