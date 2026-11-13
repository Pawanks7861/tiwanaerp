<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Services\Scrap\ScrapMasterBootstrapper;
use Illuminate\Console\Command;

class ScrapBootstrapMastersCommand extends Command
{
    protected $signature = 'scrap:bootstrap-masters {--company= : Company ID (default: all companies)}';

    protected $description = 'Seed default scrap categories and types for existing companies (idempotent).';

    public function handle(ScrapMasterBootstrapper $bootstrapper): int
    {
        $companyId = $this->option('company');

        $query = Company::query()->orderBy('id');
        if ($companyId !== null && $companyId !== '') {
            $query->whereKey((int) $companyId);
        }

        $count = 0;
        $query->each(function (Company $company) use ($bootstrapper, &$count) {
            $this->line("Seeding scrap masters for company #{$company->id} ({$company->name})…");
            $bootstrapper->seedForCompany($company);
            $count++;
        });

        $this->info("Done. {$count} compan".($count === 1 ? 'y' : 'ies').' updated.');

        return self::SUCCESS;
    }
}
