<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\Crm\Client;
use App\Models\Projects\Project;
use App\Support\Crm\DefaultClientResolver;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

class BackfillDefaultClientCommand extends Command
{
    protected $signature = 'tiwana:backfill-default-client
                            {--company= : Optional company id (default: all companies)}
                            {--dry-run : Report changes without writing}';

    protected $description = 'Ensure Tiwana Nutrition Global Private Limited is the default client and backfill null project.client_id.';

    public function handle(DefaultClientResolver $resolver, CurrentCompany $tenancy): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyId = $this->option('company');

        $companies = Company::query()
            ->when($companyId !== null && $companyId !== '', fn ($q) => $q->whereKey((int) $companyId))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $tenancy->runAs($company, function () use ($resolver, $company, $dryRun) {
                if ($dryRun) {
                    $existing = $resolver->client($company) ?? $this->peekClient($company);
                    $this->line(sprintf(
                        '[dry-run] Company #%d (%s): default client %s',
                        $company->id,
                        $company->code,
                        $existing ? "#{$existing->id} {$existing->company_name}" : 'would be created/configured',
                    ));
                } else {
                    $client = $resolver->ensure($company);
                    $this->info(sprintf(
                        'Company #%d (%s): default client #%d %s (%s)',
                        $company->id,
                        $company->code,
                        $client->id,
                        $client->company_name,
                        $client->code,
                    ));
                }

                $client = $dryRun
                    ? ($resolver->client($company) ?? $this->peekClient($company))
                    : $resolver->require($company);

                if ($client === null) {
                    $this->warn('  No client resolved; skipping project backfill.');

                    return;
                }

                $query = Project::query()
                    ->where('company_id', $company->id)
                    ->whereNull('client_id');

                $count = (clone $query)->count();
                if ($count === 0) {
                    $this->line('  Projects with null client_id: 0');

                    return;
                }

                if ($dryRun) {
                    $this->line("  Would backfill {$count} project(s) → client #{$client->id}");

                    return;
                }

                $updated = $query->update(['client_id' => $client->id]);
                $this->line("  Backfilled {$updated} project(s) → client #{$client->id}");
            });
        }

        return self::SUCCESS;
    }

    private function peekClient(Company $company): ?Client
    {
        return Client::query()
            ->where('company_id', $company->id)
            ->where(function ($q) {
                $q->whereRaw('LOWER(code) = ?', [strtolower(DefaultClientResolver::CLIENT_CODE)])
                    ->orWhereRaw('LOWER(company_name) = ?', [strtolower(DefaultClientResolver::CLIENT_NAME)]);
            })
            ->first();
    }
}
