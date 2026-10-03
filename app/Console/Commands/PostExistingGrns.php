<?php

namespace App\Console\Commands;

use App\Enums\Procurement\GrnStatus;
use App\Models\Core\Company;
use App\Models\Masters\Warehouse;
use App\Models\Procurement\Grn;
use App\Services\Inventory\GrnStockPoster;
use App\Services\Inventory\StockLedgerService;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time (and safely repeatable) backfill: approved GRNs whose accepted lines have no grn_in
 * posting yet are posted to stock. GRNs approved before Phase 4 may have no warehouse; they are
 * only posted when --warehouse names the receiving store (the GRN itself stays unchanged).
 */
#[Signature('inventory:post-existing-grns
    {--company= : Company id (default: every company)}
    {--warehouse= : Receiving warehouse id for approved GRNs that have none}
    {--dry-run : Show what would be posted without writing anything}')]
#[Description('Post approved GRNs that are not yet in the stock ledger (idempotent)')]
class PostExistingGrns extends Command
{
    public function handle(GrnStockPoster $poster, StockLedgerService $ledger, CurrentCompany $tenancy): int
    {
        $fallback = null;
        if ($this->option('warehouse')) {
            $fallback = $tenancy->withoutScope(fn () => Warehouse::query()->whereKey((int) $this->option('warehouse'))->first());
            if ($fallback === null) {
                $this->error('Warehouse not found.');

                return self::FAILURE;
            }
        }

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->when($fallback, fn ($q) => $q->whereKey($fallback->company_id))
            ->orderBy('id')->get();

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $posted = 0;
        $skipped = [];
        $failed = 0;

        foreach ($companies as $company) {
            $tenancy->runAs($company, function () use ($poster, $ledger, $fallback, $dryRun, &$rows, &$posted, &$skipped, &$failed) {
                $grns = Grn::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', app(CurrentCompany::class)->id())
                    ->where('status', GrnStatus::Approved)->orderBy('id')->get();
                $projected = [];

                foreach ($grns as $grn) {
                    $pending = $poster->unpostedItems($grn);
                    if ($pending === []) {
                        continue;
                    }

                    $warehouse = $grn->warehouse_id !== null ? Warehouse::query()->withTrashed()->find($grn->warehouse_id) : $fallback;
                    if ($warehouse === null) {
                        $skipped[] = "{$grn->grn_number}: no warehouse on the GRN; rerun with --warehouse=<id>.";

                        continue;
                    }
                    if ($grn->warehouse_id === null && $warehouse->project_id !== null && (int) $warehouse->project_id !== (int) $grn->project_id) {
                        $skipped[] = "{$grn->grn_number}: warehouse {$warehouse->code} belongs to another project.";

                        continue;
                    }

                    if ($dryRun) {
                        foreach ($pending as $item) {
                            $cost = $poster->unitCost($item);
                            $value = Decimal::of($item->accepted_qty)->times($cost)->round(Decimal::MONEY_SCALE);
                            $key = $warehouse->id.':'.$item->material_id;
                            $projected[$key] ??= $ledger->balance($warehouse->id, $item->material_id);
                            $projected[$key]['quantity'] = $projected[$key]['quantity']->plus($item->accepted_qty);
                            $projected[$key]['value'] = $projected[$key]['value']->plus($value);
                            $rows[] = [$grn->grn_number, $warehouse->code, $item->material_id, Decimal::of($item->accepted_qty)->toQuantity(), $cost->toRate(), $value->toMoney(),
                                $projected[$key]['quantity']->toQuantity().' / '.$projected[$key]['value']->toMoney(), 'would post'];
                        }

                        continue;
                    }

                    try {
                        $remarks = $grn->warehouse_id === null ? "{$grn->grn_number} (backfill; GRN approved without a warehouse)" : "{$grn->grn_number} (backfill)";
                        foreach ($poster->post($grn, $fallback, $remarks) as $result) {
                            if (! $result['created']) {
                                continue;
                            }
                            $txn = $result['transaction'];
                            $balance = $ledger->balance($txn->warehouse_id, $txn->material_id);
                            $rows[] = [$grn->grn_number, $warehouse->code, $txn->material_id, $txn->qty_in, $txn->unit_cost, $txn->value,
                                $balance['quantity']->toQuantity().' / '.$balance['value']->toMoney(), "posted #{$txn->id}"];
                            $posted++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("{$grn->grn_number}: {$e->getMessage()}");
                    }
                }
            });
        }

        if ($rows !== []) {
            $this->table(['GRN', 'Warehouse', 'Material id', 'Qty', 'Unit cost', 'Value', 'Balance qty / value', 'Result'], $rows);
        }
        foreach ($skipped as $message) {
            $this->warn($message);
        }

        $this->line($dryRun
            ? 'Dry run: '.count($rows).' line(s) would be posted, nothing was written.'
            : "Posted {$posted} line(s).".($failed ? " {$failed} GRN(s) failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
