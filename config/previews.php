<?php

return [

    /*
     * CAD previews stay on this server. auto tries an approved ODA tool first, then LibreDWG.
     * Paths come from the environment. They are never sent to the browser.
     */
    'cad' => [
        'driver' => env('CAD_PREVIEW_DRIVER', 'auto'),
        'libredwg_binary' => env('LIBREDWG_DWG2SVG', 'dwg2SVG'),
        'oda_binary' => env('ODA_CONVERTER_BINARY'),
        'oda_version' => env('ODA_CONVERTER_VERSION', 'ACAD2018'),
        'oda_format' => env('ODA_CONVERTER_FORMAT', 'PDF'),
        'timeout' => (int) env('CAD_PREVIEW_TIMEOUT', 180),
        'max_svg_bytes' => (int) env('CAD_PREVIEW_MAX_SVG_BYTES', 2_000_000),
        'min_output_bytes' => 40,
    ],

    /*
     * Drawings open in the browser from the private stream.
     * Above this size the viewer warns that parsing may use a lot of memory.
     */
    'dwg' => [
        'warn_bytes' => (int) env('CAD_VIEWER_WARN_BYTES', 25 * 1024 * 1024),
    ],

];
