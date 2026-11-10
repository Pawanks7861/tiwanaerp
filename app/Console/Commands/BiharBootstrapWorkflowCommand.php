<?php

namespace App\Console\Commands;

use App\Services\Projects\BiharWorkflowBootstrapper;
use Illuminate\Console\Command;

class BiharBootstrapWorkflowCommand extends Command
{
    protected $signature = 'bihar:bootstrap-workflow';

    protected $description = 'Provision Bihar (BHR_60TPH) users, settings and project approval workflows (idempotent).';

    public function handle(BiharWorkflowBootstrapper $bootstrapper): int
    {
        $result = $bootstrapper->run();

        $this->info(sprintf(
            'Bihar workflow bootstrap complete for company #%d, project %s (#%d), %d team users.',
            $result['company_id'],
            $result['project_code'],
            $result['project_id'],
            $result['users'],
        ));

        return self::SUCCESS;
    }
}
