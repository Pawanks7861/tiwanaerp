<?php

namespace App\Console\Commands;

use App\Services\Core\LiveBootstrapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('erp:ensure-superadmin {--password= : Superadmin password. Defaults to SUPERADMIN_PASSWORD.} {--force : Required in production.}')]
#[Description('Create or reset the Tiwana company and superadmin login')]
class EnsureSuperAdmin extends Command
{
    public function handle(LiveBootstrapper $bootstrapper): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run on production without --force.');

            return self::FAILURE;
        }

        try {
            $result = $bootstrapper->ensure(
                filled($this->option('password')) ? (string) $this->option('password') : null,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $user = $result['user'];
        $company = $result['company'];
        $this->info("Superadmin {$user->email} can sign in to {$company->code}.");

        return self::SUCCESS;
    }
}
