<?php

return [

    /*
     * Over-receipt tolerance for GRNs, in percent of the ordered quantity. A project setting
     * (project_settings key "procurement.grn_tolerance_percent") overrides the company setting
     * (company_settings, same key). Extra quantity is allowed on a GRN when a reason is confirmed;
     * this percent is no longer a hard cap.
     */
    'grn_tolerance_percent' => '0',

    /*
     * RFQ, vendor quotations and bid comparison. Off hides that workflow and blocks its routes.
     * Material request → purchase order → goods receipt stays available. Tables and history remain.
     * A company setting with the same key overrides this default.
     */
    'rfq_enabled' => false,

];
