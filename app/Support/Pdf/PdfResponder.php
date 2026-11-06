<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\PDF;
use Symfony\Component\HttpFoundation\Response;

/**
 * One generated PDF, served either inline (view and print) or as a download.
 * Callers authorise the document before this runs.
 */
class PdfResponder
{
    public static function send(PDF $pdf, string $filename, bool $inline): Response
    {
        $filename = str_replace(['/', '\\', '"', "\r", "\n", "\0"], '-', $filename);
        if ($filename === '' || $filename === '-') {
            $filename = 'document.pdf';
        }
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }
}
