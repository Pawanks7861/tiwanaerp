<?php

namespace App\Services\Boq;

use App\Enums\Boq\RateComparisonGstMode;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqRateComparisonQuote;
use App\Models\Masters\Vendor;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Advanced BOQ rate comparison against internal cost / approved BOQ rate.
 * Comparison only — never awards vendors or creates RFQ/PO.
 */
class RateComparisonService
{
    /**
     * @return array{
     *   quotes: list<array<string, mixed>>,
     *   internal_cost_rate: string,
     *   approved_boq_rate: string,
     *   lowest_effective_rate: string|null,
     *   locked: bool
     * }
     */
    public function workspacePayload(BoqItem $item): array
    {
        $item->loadMissing(['boq', 'unit:id,symbol']);
        $quotes = BoqRateComparisonQuote::query()
            ->where('boq_item_id', $item->id)
            ->where('is_active', true)
            ->with('vendor:id,name,code')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $rows = $quotes->map(fn (BoqRateComparisonQuote $q) => $this->quotePayload($q))->all();
        $lowest = null;
        foreach ($rows as $row) {
            if (! ($row['is_lowest'] ?? false)) {
                continue;
            }
            $lowest = $row['effective_rate'];
            break;
        }

        return [
            'quotes' => $rows,
            'internal_cost_rate' => Decimal::of($item->cost_rate ?? '0')->toRate(),
            'approved_boq_rate' => Decimal::of($item->client_rate ?? '0')->toRate(),
            'lowest_effective_rate' => $lowest,
            'locked' => ! $item->boq->isEditable(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{payload: array<string, mixed>}
     */
    public function saveQuotes(BoqItem $item, array $rows, User $user): array
    {
        $item->loadMissing('boq');
        $boq = $item->boq;
        $boq->assertEditable();

        $payload = DB::transaction(function () use ($item, $rows, $user, $boq) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();
            $lockedBoq->assertEditable();

            $internal = Decimal::of($lockedItem->cost_rate ?? '0')->toRate();
            $approved = Decimal::of($lockedItem->client_rate ?? '0')->toRate();

            $computed = [];
            foreach (array_values($rows) as $i => $row) {
                $vendorId = isset($row['vendor_id']) && $row['vendor_id'] !== '' && $row['vendor_id'] !== null
                    ? (int) $row['vendor_id']
                    : null;
                $vendorName = trim((string) ($row['vendor_name'] ?? ''));
                if ($vendorId !== null) {
                    $vendor = Vendor::query()
                        ->whereKey($vendorId)
                        ->where('company_id', $lockedBoq->company_id)
                        ->first();
                    if ($vendor === null) {
                        throw ValidationException::withMessages(["quotes.$i.vendor_id" => 'Choose a vendor from this company.']);
                    }
                    if ($vendorName === '') {
                        $vendorName = $vendor->name;
                    }
                }
                if ($vendorName === '') {
                    throw ValidationException::withMessages(["quotes.$i.vendor_name" => 'Enter a vendor / contractor name.']);
                }

                $calc = RateComparisonCalculator::quote([
                    'quoted_rate' => (string) ($row['quoted_rate'] ?? '0'),
                    'gst_mode' => (string) ($row['gst_mode'] ?? RateComparisonGstMode::Inclusive->value),
                    'gst_percent' => $row['gst_percent'] ?? null,
                    'internal_cost_rate' => $internal,
                    'approved_boq_rate' => $approved,
                ]);

                $computed[] = array_merge($calc, [
                    'id' => isset($row['id']) ? (int) $row['id'] : null,
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'remarks' => isset($row['remarks']) ? (string) $row['remarks'] : null,
                    'is_active' => true,
                    'sequence' => $i,
                ]);
            }

            $computed = RateComparisonCalculator::markLowest($computed);
            $kept = [];

            foreach ($computed as $row) {
                $model = null;
                if (! empty($row['id'])) {
                    $model = BoqRateComparisonQuote::query()
                        ->whereKey($row['id'])
                        ->where('boq_item_id', $lockedItem->id)
                        ->lockForUpdate()
                        ->first();
                }
                if ($model === null) {
                    $model = new BoqRateComparisonQuote;
                    $model->forceFill([
                        'company_id' => $lockedBoq->company_id,
                        'project_id' => $lockedBoq->project_id,
                        'boq_id' => $lockedBoq->id,
                        'boq_item_id' => $lockedItem->id,
                        'boq_line_uid' => $lockedItem->line_uid,
                        'created_by' => $user->id,
                    ]);
                }

                $model->forceFill([
                    'vendor_id' => $row['vendor_id'],
                    'vendor_name' => $row['vendor_name'],
                    'quoted_rate' => $row['quoted_rate'],
                    'gst_mode' => $row['gst_mode'],
                    'gst_percent' => $row['gst_percent'],
                    'effective_rate' => $row['effective_rate'],
                    'internal_cost_rate_snapshot' => $row['internal_cost_rate_snapshot'],
                    'approved_boq_rate_snapshot' => $row['approved_boq_rate_snapshot'],
                    'variance_vs_internal' => $row['variance_vs_internal'],
                    'variance_vs_internal_percent' => $row['variance_vs_internal_percent'],
                    'variance_vs_approved' => $row['variance_vs_approved'],
                    'variance_vs_approved_percent' => $row['variance_vs_approved_percent'],
                    'is_lowest' => $row['is_lowest'],
                    'remarks' => $row['remarks'],
                    'sequence' => $row['sequence'],
                    'is_active' => true,
                    'updated_by' => $user->id,
                ])->save();

                $kept[] = $model->id;
            }

            BoqRateComparisonQuote::query()
                ->where('boq_item_id', $lockedItem->id)
                ->when($kept !== [], fn ($q) => $q->whereNotIn('id', $kept))
                ->when($kept === [], fn ($q) => $q)
                ->get()
                ->each(function (BoqRateComparisonQuote $quote) use ($user) {
                    $quote->forceFill(['is_active' => false, 'updated_by' => $user->id, 'is_lowest' => false])->save();
                });

            return $this->workspacePayload($lockedItem->fresh());
        });

        return ['payload' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(BoqRateComparisonQuote $quote): array
    {
        return [
            'id' => $quote->id,
            'vendor_id' => $quote->vendor_id,
            'vendor_name' => $quote->vendor_name,
            'vendor_label' => $quote->vendor
                ? trim(($quote->vendor->code ? $quote->vendor->code.' — ' : '').$quote->vendor->name)
                : $quote->vendor_name,
            'quoted_rate' => Decimal::of($quote->quoted_rate)->toRate(),
            'gst_mode' => $quote->gst_mode?->value ?? RateComparisonGstMode::Inclusive->value,
            'gst_percent' => $quote->gst_percent !== null ? Decimal::of($quote->gst_percent)->round(Decimal::PERCENT_SCALE)->toString() : null,
            'effective_rate' => Decimal::of($quote->effective_rate)->toRate(),
            'internal_cost_rate_snapshot' => Decimal::of($quote->internal_cost_rate_snapshot)->toRate(),
            'approved_boq_rate_snapshot' => Decimal::of($quote->approved_boq_rate_snapshot)->toRate(),
            'variance_vs_internal' => Decimal::of($quote->variance_vs_internal)->toRate(),
            'variance_vs_internal_percent' => $quote->variance_vs_internal_percent !== null
                ? Decimal::of($quote->variance_vs_internal_percent)->round(Decimal::PERCENT_SCALE)->toString()
                : null,
            'variance_vs_approved' => Decimal::of($quote->variance_vs_approved)->toRate(),
            'variance_vs_approved_percent' => $quote->variance_vs_approved_percent !== null
                ? Decimal::of($quote->variance_vs_approved_percent)->round(Decimal::PERCENT_SCALE)->toString()
                : null,
            'is_lowest' => (bool) $quote->is_lowest,
            'remarks' => $quote->remarks,
            'sequence' => $quote->sequence,
        ];
    }
}
