<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Services\Inventory\LowStockScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:scan-low-stock {--company= : Company id (default: every active company)}')]
#[Description('Alert the store team about items at or below their reorder level (deduplicated)')]
class ScanLowStock extends Command
{
    public function handle(LowStockScanner $scanner): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id), fn ($q) => $q->where('is_active', true))
            ->orderBy('id')->get();

        foreach ($companies as $company) {
            $result = $scanner->scan($company);
            $this->line("Company #{$company->id}: {$result['low']} low, {$result['alerted']} new alert(s), {$result['cleared']} recovered.");
        }

        return self::SUCCESS;
    }
}
