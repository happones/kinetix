<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

use RuntimeException;

/**
 * HTML → PDF conversion behind the popular Laravel drivers, auto-detected
 * (override with `kinetix.pdf.driver`):
 *
 *  - `spatie`   → spatie/laravel-pdf (Browsershot/Chromium — best fidelity)
 *  - `barryvdh` → barryvdh/laravel-dompdf
 *  - `dompdf`   → dompdf/dompdf directly (no wrapper needed)
 */
class PdfDriver
{
    public static function output(string $html, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $driver = (string) config('kinetix.pdf.driver', 'auto');

        if (in_array($driver, ['auto', 'spatie'], true) && class_exists('Spatie\\LaravelPdf\\Facades\\Pdf')) {
            return static::viaSpatie($html, $paper, $orientation);
        }

        if (in_array($driver, ['auto', 'barryvdh'], true) && class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            $facade = 'Barryvdh\\DomPDF\\Facade\\Pdf';

            return (string) $facade::loadHTML($html)->setPaper($paper, $orientation)->output();
        }

        if (in_array($driver, ['auto', 'dompdf'], true) && class_exists('Dompdf\\Dompdf')) {
            $class  = 'Dompdf\\Dompdf';
            $dompdf = new $class(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($html);
            $dompdf->setPaper(strtoupper($paper), $orientation);
            $dompdf->render();

            return (string) $dompdf->output();
        }

        throw new RuntimeException(
            'No PDF driver available. Install one of: dompdf/dompdf, barryvdh/laravel-dompdf or spatie/laravel-pdf '
            .'(or set kinetix.pdf.driver to an installed one).'
        );
    }

    protected static function viaSpatie(string $html, string $paper, string $orientation): string
    {
        $facade = 'Spatie\\LaravelPdf\\Facades\\Pdf';
        $pdf    = $facade::html($html)->format($paper);

        if ($orientation === 'landscape') {
            $pdf = $pdf->landscape();
        }

        return (string) base64_decode((string) $pdf->base64(), true);
    }
}
