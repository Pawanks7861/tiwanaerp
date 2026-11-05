<?php

namespace App\Http\Controllers\Boq\Concerns;

use App\Support\Boq\BoqCompanySettings;

trait RequiresAdvancedBoqCalculations
{
    protected function requireAdvancedBoqCalculations(): void
    {
        abort_unless(BoqCompanySettings::enabled(), 403);
    }
}
