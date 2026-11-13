<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\Masters\Material;
use App\Models\Masters\Warehouse;
use App\Services\Inventory\InventoryReconciler;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('inventory:reconcile
    {--company= : Company id (default: every company)}
    {--warehouse= : Only this warehouse id}
    {--material= : Only this material id}
    {--fix : Rewrite drifted stock_balances rows from the ledger (the ledger is never changed)}
    {--post-missing-grns : Idempotently post approved GRNs that still lack grn_in rows}
    {--force : Do not ask for confirmation before --fix}')]
#[Description('Recompute stock balances from the stock ledger and report (or repair) cache drift')]
class ReconcileInventory extends Command
{
    public function handle(InventoryReconciler $reconciler, CurrentCompany $tenancy): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        $totalDrift = 0;
        $totalFixed = 0;
        $totalUnposted = 0;
        $totalRecovered = 0;
        $totalFailed = 0;

        foreach ($companies as $company) {
            [$drift, $fixed, $unpostedCount, $recovered, $failed] = $tenancy->runAs($company, function () use ($reconciler, $company) {
                $warehouseId = $this->option('warehouse') ? (int) $this->option('warehouse') : null;
                if ($warehouseId !== null && ! Warehouse::query()->withTrashed()->whereKey($warehouseId)->exists()) {
                    return [[], 0, 0, 0, 0];
                }

                $drift = $reconciler->drift($warehouseId, $this->option('material') ? (int) $this->option('material') : null);
                $unposted = $reconciler->unpostedGrns();

                $this->line("Company #{$company->id} {$company->name}: ".count($drift).' drifted balance(s), '.count($unposted).' approved GRN(s) not posted to stock.');

                if ($drift !== []) {
                    $names = $this->names($drift);
                    $this->table(
                        ['Warehouse', 'Material', 'Ledger qty', 'Cached qty', 'Ledger value', 'Cached value', 'Ledger avg', 'Cached avg'],
                        array_map(fn (array $row) => [
                            $names['w'][$row['warehouse_id']] ?? "#{$row['warehouse_id']}",
                            $names['m'][$row['material_id']] ?? "#{$row['material_id']}",
                            $row['ledger_qty'], $row['cached_qty'] ?? '(none)',
                            $row['ledger_value'], $row['cached_value'] ?? '(none)',
                            $row['ledger_avg'], $row['cached_avg'] ?? '(none)',
                        ], $drift),
                    );
                }

                foreach ($unposted as $row) {
                    $this->warn("  {$row['grn']->grn_number}: {$row['items']} accepted line(s) without a grn_in posting (see inventory:post-existing-grns or --post-missing-grns).");
                    Log::warning('grn.stock_posting_incomplete', [
                        'event' => 'grn.stock_posting_incomplete',
                        'grn_id' => $row['grn']->id,
                        'grn_number' => $row['grn']->grn_number,
                        'company_id' => $company->id,
                        'unposted_items' => $row['items'],
                        'recovery' => 'inventory:post-existing-grns or inventory:reconcile --post-missing-grns',
                    ]);
                }

                $fixed = 0;
                if ($drift !== [] && $this->option('fix')) {
                    if ($this->option('force') || $this->confirm('Rewrite '.count($drift).' cached balance(s) from the ledger?')) {
                        $fixed = $reconciler->fix($drift);
                        $this->info("  Repaired {$fixed} cached balance(s).");
                    }
                }

                $recovered = 0;
                $failed = 0;
                if ($this->option('post-missing-grns') && $unposted !== []) {
                    $result = $reconciler->recoverUnpostedGrns();
                    $recovered = $result['posted_lines'];
                    $failed = count($result['failed']);
                    $this->info("  Recovered {$recovered} grn_in line(s).".($failed ? " {$failed} GRN(s) failed." : ''));
                    foreach ($result['failed'] as $row) {
                        $this->error("  {$row['grn_number']}: {$row['message']}");
                    }
                }

                return [$drift, $fixed, count($unposted), $recovered, $failed];
            });

            $totalDrift += count($drift);
            $totalFixed += $fixed;
            $totalUnposted += $unpostedCount;
            $totalRecovered += $recovered;
            $totalFailed += $failed;
        }

        $this->newLine();
        $this->line("Drift found: {$totalDrift}. Repaired: {$totalFixed}. Unposted GRNs: {$totalUnposted}. Recovered lines: {$totalRecovered}.");

        if ($totalFailed > 0) {
            return self::FAILURE;
        }

        return $totalDrift > $totalFixed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array{warehouse_id: int, material_id: int}>  $drift
     * @return array{w: array<int, string>, m: array<int, string>}
     */
    private function names(array $drift): array
    {
        return [
            'w' => Warehouse::query()->withTrashed()->whereIn('id', array_column($drift, 'warehouse_id'))->pluck('code', 'id')->all(),
            'm' => Material::query()->withTrashed()->whereIn('id', array_column($drift, 'material_id'))->pluck('code', 'id')->all(),
        ];
    }
}
