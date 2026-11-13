<?php

namespace App\Reports\Definitions;

final class ElectricityRebateSubsidyReport extends ElectricityPenaltyRebateReport
{
    public function __construct()
    {
        parent::__construct(penalties: false);
    }
}
