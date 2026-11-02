<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Universal data import
    |--------------------------------------------------------------------------
    |
    | Uploads land on the private disk. Large files validate/process on the
    | database queue (QUEUE_CONNECTION=database). Run a queue worker in
    | production: php artisan queue:work --queue=imports,default
    |
    */

    'disk' => env('IMPORT_DISK', 'private'),

    'path' => 'imports',

    'max_upload_kb' => (int) env('IMPORT_MAX_UPLOAD_KB', 5120),

    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),

    'chunk_size' => (int) env('IMPORT_CHUNK_SIZE', 100),

    'preview_rows' => 30,

    'queue' => env('IMPORT_QUEUE', 'imports'),

    'queue_threshold_rows' => (int) env('IMPORT_QUEUE_THRESHOLD', 200),

    'retention_days' => (int) env('IMPORT_RETENTION_DAYS', 30),

    'mimes' => ['xlsx', 'xls', 'csv'],

];
