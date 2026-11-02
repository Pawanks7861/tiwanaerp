<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\OperationalDataResetter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('erp:reset-operational-data {--force : Required. Permanently delete operational rows.} {--superadmin-email= : Superadmin to keep. Defaults to the first is_super_admin user.} {--company-code= : Company to keep. Defaults to the superadmin current company, else the first company.} {--purge-files : Also delete private uploaded files except the kept company logo/favicon.}')]
#[Description('Remove dummy/operational data, keeping one superadmin and one provisioned company')]
class ResetOperationalData extends Command
{
    public function handle(OperationalDataResetter $resetter): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force. This permanently deletes projects, documents, BOQs and extra users.');

            return self::FAILURE;
        }

        $admin = $this->resolveSuperAdmin();
        if ($admin === null) {
            return self::FAILURE;
        }

        $company = $this->resolveCompany($admin);
        if ($company === null) {
            return self::FAILURE;
        }

        $this->warn("Deleting operational data. Keeping {$admin->email} and company {$company->code}.");

        $resetter->reset($admin, $company);
        $company = $company->fresh() ?? $company;

        $purged = 0;
        if ($this->option('purge-files')) {
            $purged = $resetter->purgePrivateFiles($company);
        }

        $this->info("Kept superadmin {$admin->email} and company {$company->code}.");
        $this->line('Other users, companies, projects and operational rows were deleted. Roles and starter masters were re-provisioned.');

        if ($this->option('purge-files')) {
            $this->line("Deleted {$purged} private file(s); company logo/favicon were kept.");
        }

        if (Hash::check('password', $admin->getRawOriginal('password'))) {
            $this->warn('The kept superadmin still uses the demo password. Change it immediately.');
        }

        return self::SUCCESS;
    }

    private function resolveSuperAdmin(): ?User
    {
        $email = trim((string) $this->option('superadmin-email'));
        $query = User::query()->where('is_super_admin', true)->orderBy('id');

        if ($email !== '') {
            $admin = $query->where('email', $email)->first();
            if ($admin === null) {
                $this->error("No superadmin user found with email {$email}.");

                return null;
            }

            return $admin;
        }

        $admin = $query->first();
        if ($admin === null) {
            $this->error('No superadmin user exists. Create one before resetting.');
        }

        return $admin;
    }

    private function resolveCompany(User $admin): ?Company
    {
        $code = strtoupper(trim((string) $this->option('company-code')));

        if ($code !== '') {
            $company = Company::query()->withTrashed()->where('code', $code)->first();
            if ($company === null) {
                $this->error("No company found with code {$code}.");

                return null;
            }

            return $company;
        }

        if ($admin->current_company_id) {
            $current = Company::query()->withTrashed()->find($admin->current_company_id);
            if ($current !== null) {
                return $current;
            }
        }

        $company = Company::query()->withTrashed()->orderBy('id')->first();
        if ($company === null) {
            $this->error('No company exists. The ERP cannot log in without one active company.');
        }

        return $company;
    }
}
