<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tiwana ERP onboarding tutorial
    |--------------------------------------------------------------------------
    |
    | Mandatory interactive tour for eligible users. Company Admin, Director
    | and Super Admin are exempt. Bump version only for major mandatory resets.
    |
    */

    'key' => env('TUTORIAL_KEY', 'tiwana-erp-onboarding'),

    'version' => env('TUTORIAL_VERSION', 'v1'),

    'enabled' => (bool) env('TUTORIAL_ENABLED', true),

    /*
    | Role names that never receive the automatic tutorial.
    */
    'exempt_roles' => [
        'Company Admin',
        'Director',
    ],

];
