<?php

namespace Database\Seeders;

use App\Services\Projects\BiharWorkflowBootstrapper;
use Illuminate\Database\Seeder;

class BiharWorkflowSeeder extends Seeder
{
    public function run(BiharWorkflowBootstrapper $bootstrapper): void
    {
        $result = $bootstrapper->run();
        $this->command?->info(sprintf(
            'Bihar workflow: project %s (#%d), %d users.',
            $result['project_code'],
            $result['project_id'],
            $result['users'],
        ));
    }
}
