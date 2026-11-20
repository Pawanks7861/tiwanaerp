<?php

namespace App\Support\Procurement;

use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;

/**
 * Procurement value for a GRN line is accepted_qty × rate (live PO rate, else GRN snapshot).
 */
final class GrnValuation
{
    public const DOC_MATERIAL_GATE_PASS = 'material_gate_pass';

    public const DOC_WEIGHMENT_SLIP = 'weighment_slip';

    public const DOC_INVOICE = 'invoice';

    /**
     * @return list<string>
     */
    public static function requiredDocumentCategories(): array
    {
        return [
            self::DOC_MATERIAL_GATE_PASS,
            self::DOC_WEIGHMENT_SLIP,
            self::DOC_INVOICE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function requiredDocumentLabels(): array
    {
        return [
            self::DOC_MATERIAL_GATE_PASS => 'Material gate pass',
            self::DOC_WEIGHMENT_SLIP => 'Weighment slip',
            self::DOC_INVOICE => 'Invoice',
        ];
    }

    public static function lineRate(GrnItem $item): Decimal
    {
        $poRate = $item->relationLoaded('purchaseOrderItem')
            ? $item->purchaseOrderItem?->rate
            : $item->purchaseOrderItem()->value('rate');

        if ($poRate !== null && $poRate !== '') {
            return Decimal::of($poRate);
        }

        return Decimal::of($item->rate);
    }

    public static function lineAmount(GrnItem $item): Decimal
    {
        return Decimal::of($item->accepted_qty)->times(self::lineRate($item))->round(Decimal::MONEY_SCALE);
    }

    /**
     * @param  Collection<int, GrnItem>|iterable<GrnItem>  $items
     */
    public static function totalAmount(iterable $items): Decimal
    {
        return Decimal::sum(collect($items)->map(fn (GrnItem $item) => self::lineAmount($item)));
    }

    /**
     * Categories still missing at least one GRN attachment.
     *
     * @return list<string>
     */
    public static function missingDocumentCategories(Grn $grn): array
    {
        $present = $grn->relationLoaded('attachments')
            ? $grn->attachments
            : $grn->attachments()->get();

        $found = $present
            ->pluck('category')
            ->filter()
            ->map(fn ($c) => strtolower((string) $c))
            ->unique()
            ->all();

        return array_values(array_filter(
            self::requiredDocumentCategories(),
            fn (string $category) => ! in_array($category, $found, true),
        ));
    }

    public static function documentsComplete(Grn $grn): bool
    {
        return self::missingDocumentCategories($grn) === [];
    }

    /**
     * @return array{complete: bool, missing: list<string>, labels: array<string, string>}
     */
    public static function documentStatus(Grn $grn): array
    {
        $missing = self::missingDocumentCategories($grn);
        $labels = self::requiredDocumentLabels();

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'missing_labels' => array_values(array_map(fn (string $c) => $labels[$c] ?? $c, $missing)),
            'required' => array_map(
                fn (string $c) => ['category' => $c, 'label' => $labels[$c] ?? $c, 'present' => ! in_array($c, $missing, true)],
                self::requiredDocumentCategories(),
            ),
        ];
    }
}
