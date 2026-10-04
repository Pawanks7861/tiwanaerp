<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-company UI
    |--------------------------------------------------------------------------
    |
    | When false, the signed-in application hides company switching and platform
    | company administration and keeps the user's active company. Tenancy,
    | memberships and company_id isolation stay in place. Set this to true to
    | restore the switcher and platform company screens.
    |
    */

    'multi_company' => filter_var(env('FEATURE_MULTI_COMPANY', false), FILTER_VALIDATE_BOOLEAN),

];
