<?php

$maxBytes = (int) env('UPLOAD_MAX_FILE_SIZE_BYTES', 1073741824);

return [

    'disk' => 'private',

    /*
     * Generic attachment ceiling: 1 GiB (binary). Every module reads this value.
     * Logo, favicon, camera photos and spreadsheet imports keep their own caps below.
     */
    'max_file_size_bytes' => $maxBytes,

    // Derived so FormRequest "max" rules stay in kilobytes without a second magic number.
    'max_kb' => (int) ceil($maxBytes / 1024),

    /*
     * One chunk must fit the web server's upload limit. 1 MiB fits a PHP upload_max_filesize
     * of 2M (current local WAMP). Production should raise the server limit for a single chunk
     * and set UPLOAD_CHUNK_BYTES=10485760 (10 MiB). Never raise it to the full 1 GiB file.
     */
    'chunk_bytes' => (int) env('UPLOAD_CHUNK_BYTES', 1024 * 1024),

    'max_chunks' => (int) env('UPLOAD_MAX_CHUNKS', 4096),

    'max_active_sessions' => (int) env('UPLOAD_MAX_ACTIVE_SESSIONS', 20),

    // Abandoned chunk directories are removed after this many hours. Completed files are kept.
    'expire_hours' => (int) env('UPLOAD_EXPIRE_HOURS', 36),

    // Null disables the check. Set a byte cap to refuse new uploads once a company is over it.
    'company_quota_bytes' => env('UPLOAD_COMPANY_QUOTA_BYTES') !== null && env('UPLOAD_COMPANY_QUOTA_BYTES') !== ''
        ? (int) env('UPLOAD_COMPANY_QUOTA_BYTES')
        : null,

    // Camera capture stays small because the thumbnail is decoded in memory. Chunked gallery
    // images use max_file_size_bytes and skip the in-memory thumbnail when they are large.
    'photo_max_kb' => (int) env('UPLOAD_PHOTO_MAX_KB', 10240),

    // Single-request image assets. Both sit far below the generic 1 GiB cap.
    // 2 MB matches a local PHP upload_max_filesize of 2M. Production may raise the logo
    // toward 5–10 MB and the favicon toward 2–5 MB once the server accepts that one request.
    'logo_max_kb' => (int) env('UPLOAD_LOGO_MAX_KB', 2048),
    'favicon_max_kb' => (int) env('UPLOAD_FAVICON_MAX_KB', 2048),

    // Spreadsheet imports are loaded by the workbook library, not streamed. Keep this small.
    'import_max_kb' => (int) env('UPLOAD_IMPORT_MAX_KB', 10240),

    // Viewer caps. These are prefixes and row windows, never a full 1 GiB read.
    'preview_text_bytes' => (int) env('UPLOAD_PREVIEW_TEXT_BYTES', 1024 * 1024),
    'preview_csv_rows' => (int) env('UPLOAD_PREVIEW_CSV_ROWS', 500),
    'preview_sheet_rows' => (int) env('UPLOAD_PREVIEW_SHEET_ROWS', 500),
    'preview_sheet_columns' => (int) env('UPLOAD_PREVIEW_SHEET_COLUMNS', 50),
    'preview_sheet_max_bytes' => (int) env('UPLOAD_PREVIEW_SHEET_MAX_BYTES', 8 * 1024 * 1024),
    'preview_archive_entries' => (int) env('UPLOAD_PREVIEW_ARCHIVE_ENTRIES', 200),

    /*
     * Known types get a MIME and signature check. Any other non-blocked extension is stored
     * as a private binary (application/octet-stream). This is not an allow-all list.
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

    /*
     * Server-executable and script types. A match on any dotted segment is refused,
     * so invoice.pdf.php is blocked as well as shell.php.
     */
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'pht', 'phps',
        'cgi', 'pl', 'py', 'sh', 'bash', 'bat', 'cmd', 'com',
        'exe', 'msi', 'dll', 'scr', 'jar',
        'htaccess', 'hta',
    ],

    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Drawing revisions (subset of "allowed"). DWG/DXF are stored as is and only downloaded.
    'drawing_extensions' => ['pdf', 'dwg', 'dxf', 'jpg', 'jpeg', 'png', 'webp'],

    // Served inline by the secure preview routes; everything else is download only.
    'preview_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],

];
