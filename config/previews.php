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
     * Optional external DWG preview. local is the default and the preferred production path.
     * ShareCAD's public viewer is not a commercial licence. Confirm permission before enabling it.
     * The frame host is fixed so it cannot be pointed at an arbitrary site.
     */
    'dwg' => [
        'provider' => env('DWG_PREVIEW_PROVIDER', 'local'),
        'sharecad_enabled' => filter_var(env('SHARECAD_DWG_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
        'frame' => 'https://iframe.sharecad.org/cadframe/load',
        'origin' => 'https://iframe.sharecad.org',
        'max_bytes' => 50 * 1024 * 1024,
        'ttl_minutes' => 10,
    ],

];
