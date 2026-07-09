<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

use Happones\Kinetix\Data\PdfTemplateData;

/**
 * A configurable PDF document format — the Mailable of PDFs. Subclass it,
 * register it (`KinetixPdf::register(QuotePdf::class)`), and mount
 * `<KinetixPdfTemplate template="quote" />` to get a live-preview
 * configurator whose settings persist per template (and per team when team
 * scoping is on).
 *
 *     class QuotePdf extends PdfTemplate
 *     {
 *         public static string $key = 'quote';
 *
 *         public function label(): string { return 'Quotation'; }
 *
 *         public function sampleData(): array { … }
 *     }
 *
 * The default `fields()` exposes the standard document knobs (accent/text
 * colors, font, title, logo, striped rows, footer, signature) and the default
 * `render()` builds a polished generic document (header, parties, line items,
 * totals, notes) — override `fields()`, `html()` or `view()` for full control.
 * Generate the final file with `->pdf($data)` (dompdf / barryvdh / spatie).
 */
abstract class PdfTemplate
{
    /**
     * Registry key (also the URL segment).
     */
    public static string $key = '';

    /**
     * Human label shown in the configurator.
     */
    public function label(): string
    {
        return (string) str(static::key())->headline();
    }

    public static function key(): string
    {
        return static::$key !== '' ? static::$key : (string) str(class_basename(static::class))->kebab();
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * The configurable knobs. Override to add/remove controls; the defaults
     * cover the standard document layout.
     *
     * @return array<int, PdfField>
     */
    public function fields(): array
    {
        return [
            PdfField::color('accent')->label('Accent color')->default('#6366f1')->palette([
                '#6366f1', '#8b5cf6', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#0f172a',
            ]),
            PdfField::color('text')->label('Text color')->default('#0f172a'),
            PdfField::select('font', ['sans' => 'Sans', 'serif' => 'Serif'])->label('Typography')->default('sans'),
            PdfField::text('doc_title')->label('Document title')->default($this->label())->maxLength(60),
            PdfField::toggle('show_logo')->label('Show logo')->default(true),
            PdfField::toggle('show_status')->label('Show status')->default(true),
            PdfField::toggle('show_sku')->label('SKU column')->default(true),
            PdfField::toggle('striped')->label('Striped rows')->default(true),
            PdfField::text('footer_text')->label('Footer text')->maxLength(255),
            PdfField::toggle('signature')->label('Signature line')->default(false),
        ];
    }

    /**
     * Paper size + orientation for the generated file.
     *
     * @return array{0: string, 1: string} [paper, orientation]
     */
    public function paper(): array
    {
        return ['a4', 'portrait'];
    }

    /**
     * Absolute URL or data URI of the logo (used when `show_logo` is on).
     */
    public function logo(): ?string
    {
        return null;
    }

    /**
     * Realistic example data for the live preview.
     *
     * @return array<string, mixed>
     */
    public function sampleData(): array
    {
        return [
            'number' => 'DOC-0001',
            'date'   => now()->toDateString(),
            'status' => 'Draft',
            'from'   => ['name' => config('app.name'), 'lines' => ['hello@example.com']],
            'to'     => ['name' => 'Acme Inc.', 'lines' => ['Jane Doe', 'jane@acme.dev']],
            'items'  => [
                ['sku' => 'SKU-1', 'name' => 'Product A', 'qty' => 2, 'price' => '50.00', 'total' => '100.00'],
                ['sku' => 'SKU-2', 'name' => 'Product B', 'qty' => 1, 'price' => '75.00', 'total' => '75.00'],
            ],
            'summary' => [
                ['label' => 'Subtotal', 'value' => '175.00'],
                ['label' => 'Tax (16%)', 'value' => '28.00'],
                ['label' => 'Total', 'value' => '203.00'],
            ],
            'notes' => null,
        ];
    }

    /**
     * Optional Blade view (receives `settings` + `data`). When null, the
     * built-in generic document is used.
     */
    public function view(): ?string
    {
        return null;
    }

    /**
     * The defaults declared by fields(), keyed by name.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            $defaults[$field->name] = $field->getDefault();
        }

        return $defaults;
    }

    /**
     * The persisted settings merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_merge($this->defaults(), PdfTemplateSetting::for(static::key()));
    }

    /**
     * Render the document HTML.
     *
     * @param array<string, mixed>|null $data     null = sampleData()
     * @param array<string, mixed>      $settings extra overrides on top of the stored settings
     */
    public function render(?array $data = null, array $settings = []): string
    {
        $settings = array_merge($this->settings(), $settings);
        $data ??= $this->sampleData();

        if (($view = $this->view()) !== null) {
            return view($view, ['settings' => $settings, 'data' => $data, 'template' => $this])->render();
        }

        return $this->html($settings, $data);
    }

    /**
     * Render and convert to a PDF binary via the configured driver.
     *
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>      $settings
     */
    public function pdf(?array $data = null, array $settings = []): string
    {
        [$paper, $orientation] = $this->paper();

        return PdfDriver::output($this->render($data, $settings), $paper, $orientation);
    }

    /**
     * The built-in generic document (header + parties + line items + totals).
     * Override for a fully custom layout without leaving PHP.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $data
     */
    protected function html(array $settings, array $data): string
    {
        return DocumentBuilder::build($this, $settings, $data);
    }

    public function toData(): PdfTemplateData
    {
        return new PdfTemplateData(
            key: static::key(),
            label: $this->label(),
            fields: array_map(static fn (PdfField $field): array => $field->toArray(), $this->fields()),
            settings: $this->settings(),
            defaults: $this->defaults(),
            hasLogo: $this->logo() !== null,
        );
    }
}
