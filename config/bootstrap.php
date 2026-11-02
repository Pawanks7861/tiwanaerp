<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First-deploy company and superadmin
    |--------------------------------------------------------------------------
    |
    | LiveBootstrapSeeder creates this company and user on `php artisan db:seed`.
    | It does not create demo projects or extra users. Set SUPERADMIN_PASSWORD
    | in production .env before seeding.
    |
    */

    'superadmin_name' => env('SUPERADMIN_NAME', 'Pawan Sharma'),
    'superadmin_email' => env('SUPERADMIN_EMAIL', 'superadmin@buildify360.test'),
    'superadmin_password' => env('SUPERADMIN_PASSWORD'),

    'company_name' => env('COMPANY_NAME', 'Tiwana Constructions'),
    'company_code' => env('COMPANY_CODE', 'TIWANA'),

];
