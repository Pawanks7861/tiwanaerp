<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Support\Finance\FinanceModuleRetirement;

trait DeniesRetiredFinanceWrites
{
    protected function denyRetiredFinanceWrite(?bool $asJson = null): never
    {
        $json = $asJson ?? request()->expectsJson();

        FinanceModuleRetirement::denyWrite($json);
    }
}
