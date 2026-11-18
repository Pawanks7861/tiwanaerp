<?php

namespace App\Services\Boq;

use App\Models\Boq\BoqItem;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

/**
 * Authoritative BOQ quantity totals grouped by unit symbol.
 * Never sums incompatible units into a single number.
 */
class BoqQtyTotals
{
    /**
     * @param  iterable<int, BoqItem>  $items
     * @return list<array{unit: string, quantity: string}>
     */
    public static function forItems(iterable $items): array
    {
        $buckets = [];

        foreach ($items as $item) {
            $item->loadMissing('unit:id,symbol');
            $symbol = $item->unit?->symbol;
            $symbol = $symbol !== null && $symbol !== '' ? (string) $symbol : '—';
            $qty = Decimal::of($item->quantity ?? '0')->round(Decimal::QTY_SCALE);
            $buckets[$symbol] = ($buckets[$symbol] ?? Decimal::zero())->plus($qty);
        }

        return self::formatBuckets($buckets);
    }

    /**
     * Group items by top-level section (includes child section lines under each parent).
     *
     * @param  Collection<int, BoqItem>  $items
     * @param  Collection<int, object{id: int, parent_id: ?int}>  $sections
     * @return array<int, list<array{unit: string, quantity: string}>>
     */
    public static function forSections(Collection $items, Collection $sections): array
    {
        $childrenByParent = $sections->groupBy(fn ($s) => $s->parent_id);
        $result = [];

        foreach ($sections->whereNull('parent_id') as $top) {
            $ids = [$top->id];
            foreach ($childrenByParent->get($top->id, collect()) as $child) {
                $ids[] = $child->id;
            }
            $result[$top->id] = self::forItems(
                $items->whereIn('boq_section_id', $ids)->values()
            );
        }

        foreach ($sections->whereNotNull('parent_id') as $child) {
            $result[$child->id] = self::forItems(
                $items->where('boq_section_id', $child->id)->values()
            );
        }

        return $result;
    }

    /**
     * @param  array<string, Decimal>  $buckets
     * @return list<array{unit: string, quantity: string}>
     */
    private static function formatBuckets(array $buckets): array
    {
        ksort($buckets);
        $out = [];
        foreach ($buckets as $unit => $qty) {
            if ($qty->isZero()) {
                continue;
            }
            $out[] = [
                'unit' => $unit,
                'quantity' => $qty->round(Decimal::QTY_SCALE)->toQuantity(),
            ];
        }

        return $out;
    }
}
