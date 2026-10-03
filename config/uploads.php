<?php

return [

    'disk' => 'private',

    // Maximum upload sizes in kilobytes.
    'max_kb' => (int) env('UPLOAD_MAX_KB', 25600),
    'photo_max_kb' => (int) env('UPLOAD_PHOTO_MAX_KB', 10240),

    /*
     * Allowed extensions mapped to the MIME types accepted for each (as detected by fileinfo from
     * the file contents, not the client-supplied header). DWG/DXF MIME detection is unreliable,
     * so those are additionally checked by signature in FileTypeGuard.
     */
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'dwg' => ['image/vnd.dwg', 'application/acad', 'application/x-dwg', 'application/octet-stream'],
        'dxf' => ['image/vnd.dxf', 'application/dxf', 'text/plain', 'application/octet-stream'],
    ],

    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

];
