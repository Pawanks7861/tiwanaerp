<?php

$origins = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_URL', 'http://localhost')))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins === [] ? ['http://localhost'] : $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
