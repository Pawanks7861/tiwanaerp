<?php

namespace App\Services\Finance;

use App\Enums\Finance\VendorBillStatus;
use App\Enums\Procurement\GrnStatus;
use App\Models\Finance\VendorBill;
use App\Models\Finance\VendorBillItem;
use App\Models\Procurement\GrnItem;
use App\Support\Math\Decimal;

/**
 * Derives 3-way match variances for a PO vendor bill without changing bill status.
 */
class VendorInvoiceMatchingService
{
  /**
   * @return array{match_status: string, extra_received: bool, lines: list<array<string, mixed>>}
   */
  public function analyze(VendorBill $bill): array
  {
    if ($bill->isDirect() || ! $bill->purchase_order_id) {
      return [
        'match_status' => 'matched',
        'extra_received' => false,
        'lines' => [],
      ];
    }

    $bill->loadMissing(['items' => fn ($q) => $q->with(['grnItem.grn', 'purchaseOrderItem'])]);
    $grnItemIds = $bill->items->pluck('grn_item_id')->filter()->all();
    $statuses = [VendorBillStatus::Submitted, ...VendorBillStatus::approvedStates()];
    $billed = $this->billedQuantities($grnItemIds, $statuses, $bill->id);

    $lines = [];
    $worst = 'matched';

    foreach ($bill->items as $item) {
      $lines[] = $line = $this->line($item, $billed, $bill->id);
      $worst = $this->worse($worst, $line['line_match_status']);
    }

    $extraReceived = collect($lines)->contains(fn (array $l) => ($l['extra_received'] ?? false) === true);

    return [
      'match_status' => $worst,
      'extra_received' => $extraReceived,
      'lines' => $lines,
    ];
  }

  /**
   * @param  array<int, Decimal>  $billed
   * @return array<string, mixed>
   */
  private function line(VendorBillItem $item, array $billed, int $billId): array
  {
    $grnItem = $item->grnItem;
    $poItem = $item->purchaseOrderItem;
    $poQty = Decimal::of($poItem?->quantity);
    $accepted = Decimal::of($grnItem?->accepted_qty);
    $previously = $billed[$item->grn_item_id] ?? Decimal::zero();
    $current = Decimal::of($item->quantity);
    $remaining = $accepted->minus($previously)->minus($current);
    $qtyVariance = $current->minus($accepted->minus($previously));
    $poRate = Decimal::of($poItem?->rate);
    $invoiceRate = Decimal::of($item->rate);
    $rateDiff = $invoiceRate->minus($poRate);
    $rateDiffPct = $poRate->isZero() ? Decimal::zero() : $rateDiff->dividedBy($poRate)->times(100);
    $expectedAmount = $current->times($poRate)->round(2);
    $invoiceAmount = Decimal::of($item->taxable_amount);
    $amountDiff = $invoiceAmount->minus($expectedAmount);
    $extraReceived = $grnItem && $accepted->greaterThan($poQty);

    $lineStatus = 'matched';
    $grnApproved = $grnItem?->grn?->status === GrnStatus::Approved;
    if ($grnItem === null || ! $grnApproved) {
      $lineStatus = 'pending_grn';
    } elseif ($current->greaterThan($accepted->minus($previously))) {
      $lineStatus = 'exception';
    } elseif ($qtyVariance->isPositive() || ! $rateDiff->isZero() || ! $amountDiff->isZero()) {
      $lineStatus = 'variance';
    }

    return [
      'vendor_bill_item_id' => $item->id,
      'description' => $item->description,
      'grn_item_id' => $item->grn_item_id,
      'po_qty' => $poQty->toQuantity(),
      'accepted_grn_qty' => $accepted->toQuantity(),
      'previously_billed' => $previously->toQuantity(),
      'current_billed' => $current->toQuantity(),
      'remaining_billable' => $remaining->toQuantity(),
      'qty_variance' => $qtyVariance->toQuantity(),
      'po_rate' => $poRate->toRate(),
      'invoice_rate' => $invoiceRate->toRate(),
      'rate_diff' => $rateDiff->toRate(),
      'rate_diff_pct' => $rateDiffPct->round(4)->toString(),
      'expected_amount' => $expectedAmount->toMoney(),
      'invoice_amount' => $invoiceAmount->toMoney(),
      'amount_diff' => $amountDiff->toMoney(),
      'extra_received' => $extraReceived,
      'line_match_status' => $lineStatus,
    ];
  }

  /**
   * @param  list<int>  $grnItemIds
   * @param  list<VendorBillStatus>  $statuses
   * @return array<int, Decimal>
   */
  private function billedQuantities(array $grnItemIds, array $statuses, int $exceptBillId): array
  {
    if ($grnItemIds === []) {
      return [];
    }

    $rows = VendorBillItem::query()->whereIn('grn_item_id', $grnItemIds)
      ->whereHas('bill', fn ($q) => $q->whereNull('deleted_at')->whereIn('status', $statuses)
        ->whereKeyNot($exceptBillId))
      ->get(['grn_item_id', 'quantity']);

    $totals = [];
    foreach ($rows as $row) {
      $totals[$row->grn_item_id] = ($totals[$row->grn_item_id] ?? Decimal::zero())->plus($row->quantity);
    }

    return $totals;
  }

  private function worse(string $current, string $candidate): string
  {
    $rank = ['matched' => 0, 'variance' => 1, 'pending_grn' => 2, 'exception' => 3];

    return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
  }
}
