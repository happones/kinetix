<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf\Contracts;

/**
 * A model (or any object) that can express itself as PDF-template data.
 * Implement it on the records you print — `KinetixPdf::render()/pdf()` and
 * `PdfTemplate::render()/pdf()` then accept the object directly:
 *
 *     class Quote extends Model implements ProvidesPdfData
 *     {
 *         public function toPdfData(): array
 *         {
 *             return ['number' => $this->number, 'items' => …];
 *         }
 *     }
 *
 *     KinetixPdf::pdf('quote', $quote);
 *
 * The interface is optional (hybrid detection): any object exposing a
 * `toPdfData(): array` method is accepted the same way.
 */
interface ProvidesPdfData
{
    /**
     * The document data consumed by the PDF template — for the built-in
     * document: number, date, status, from, to, items, summary, notes.
     *
     * @return array<string, mixed>
     */
    public function toPdfData(): array;
}
