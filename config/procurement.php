<?php

return [

    /*
     * Over-receipt tolerance for GRNs, in percent of the ordered quantity. A project setting
     * (project_settings key "procurement.grn_tolerance_percent") overrides the company setting
     * (company_settings, same key), which overrides this default. 0 = no over-receipt allowed.
     */
    'grn_tolerance_percent' => '0',

];
