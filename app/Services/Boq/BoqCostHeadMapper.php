<?php

namespace App\Services\Boq;

use App\Enums\Boq\CostComponentType;
use App\Enums\CostHead;

/**
 * Central Stage 6 mapping between Cost Build-Up component types and project_cost_ledger heads.
 * Display heads for Estimated vs Actual: Material, Labour, Equipment, Subcontract, Other.
 * Ledger Overhead posts roll into Other (no silent rewrite of historical ledger rows).
 */
final class BoqCostHeadMapper
{
    public const DISPLAY_HEADS = ['material', 'labour', 'equipment', 'subcontract', 'other'];

    public static function fromComponentType(CostComponentType|string $type): string
    {
        $value = $type instanceof CostComponentType ? $type->value : (string) $type;

        return match ($value) {
            CostComponentType::Material->value => 'material',
            CostComponentType::Labour->value => 'labour',
            CostComponentType::Equipment->value => 'equipment',
            CostComponentType::Subcontract->value => 'subcontract',
            default => 'other',
        };
    }

    public static function fromLedgerHead(CostHead|string $head): string
    {
        $value = $head instanceof CostHead ? $head->value : (string) $head;

        return match ($value) {
            CostHead::Material->value => 'material',
            CostHead::Labour->value => 'labour',
            CostHead::Equipment->value => 'equipment',
            CostHead::Subcontract->value => 'subcontract',
            CostHead::Overhead->value, CostHead::Other->value => 'other',
            default => 'other',
        };
    }

    public static function label(string $displayHead): string
    {
        return match ($displayHead) {
            'material' => 'Material',
            'labour' => 'Labour',
            'equipment' => 'Equipment',
            'subcontract' => 'Subcontract',
            'other' => 'Other',
            'unallocated' => 'Unallocated',
            default => ucfirst($displayHead),
        };
    }

    /** @return array<string, string> zeroed money strings keyed by display head */
    public static function emptyHeadMoney(): array
    {
        $out = [];
        foreach (self::DISPLAY_HEADS as $h) {
            $out[$h] = '0.00';
        }

        return $out;
    }
}
