<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

/**
 * Facade-style entry point for the PDF templates module.
 *
 *     // Provider boot()
 *     KinetixPdf::register(QuotePdf::class);
 *
 *     // Anywhere: render/download with real data
 *     $html = KinetixPdf::render('quote', $data);
 *     $pdf  = KinetixPdf::pdf('quote', $data);   // binary string
 */
class KinetixPdf
{
    /**
     * @param class-string<PdfTemplate> $templateClass
     */
    public static function register(string $templateClass): void
    {
        app(PdfTemplateRegistry::class)->register($templateClass);
    }

    public static function template(string $key): ?PdfTemplate
    {
        return app(PdfTemplateRegistry::class)->get($key);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function render(string $key, ?array $data = null): string
    {
        $template = static::template($key);

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown PDF template [{$key}].");
        }

        return $template->render($data);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function pdf(string $key, ?array $data = null): string
    {
        $template = static::template($key);

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown PDF template [{$key}].");
        }

        return $template->pdf($data);
    }
}
