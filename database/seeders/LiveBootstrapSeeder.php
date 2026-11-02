<?php

namespace Database\Seeders;

use App\Services\Core\LiveBootstrapper;
use Illuminate\Database\Seeder;

/**
 * Idempotent live start: one Tiwana company and one superadmin. No dummy projects or extra users.
 */
class LiveBootstrapSeeder extends Seeder
{
    public function run(LiveBootstrapper $bootstrapper): void
    {
        $result = $bootstrapper->ensure();
        $user = $result['user'];
        $company = $result['company'];

        $this->command?->info("Live bootstrap ready: {$user->email} / {$company->code}.");
        $this->command?->warn('Sign in with SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD from .env.');
    }
}
