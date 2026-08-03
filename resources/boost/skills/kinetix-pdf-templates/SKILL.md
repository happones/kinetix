---
name: kinetix-pdf-templates
description: "Configurable PDF document formats (quotes, invoices, receipts): Mailable-style PdfTemplate classes with declarative fields, a live-preview configurator component, per-team persisted settings and dompdf/barryvdh/spatie drivers. Activates when building customizable PDFs or document generation."
license: MIT
metadata:
  author: happones
---

# Kinetix PDF Templates Development

## When to Apply

Activate this skill when:
- Declaring a document format (`PdfTemplate` subclass, `PdfField` knobs) or registering it (`KinetixPdf::register`).
- Mounting `<KinetixPdfTemplate template="…" />` (configurator + live preview + download).
- Generating real documents (`KinetixPdf::render/pdf`) or choosing a driver (`kinetix.pdf.driver`).

## Documentation

For full details, reference `docs/pdf-templates.md` (published at https://happones.github.io/kinetix/pdf-templates).

## Essentials

```php
class QuotePdf extends PdfTemplate
{
    public static string $key = 'quote';
    public function sampleData(): array { /* preview data */ }
}

KinetixPdf::register(QuotePdf::class);          // provider boot()
$pdf = KinetixPdf::pdf('quote', $quote);        // binary, stored settings applied
```

- Models implement `Pdf\Contracts\ProvidesPdfData` (`toPdfData(): array` mapping the record onto the data shape) and are passed directly to `render()/pdf()`; the interface is optional (hybrid detection — any object with the method works), plain arrays are always accepted, other objects throw.

- Default `fields()` = standard knobs (accent/text color, font, title, logo, status, SKU, striped, footer, signature); override with `PdfField::color/text/select/toggle/number`.
- Default layout = built-in generic document (`DocumentBuilder`, dompdf-safe inline CSS). Custom: override `html()` (PHP) or `view()` (Blade, receives settings/data/template).
- Settings persist in `kinetix_pdf_templates` (publish `--tag=kinetix-pdf-migrations`), per team when team scoping applies. Endpoints gated by `viewKinetixPdf` (local-only default).
- Drivers auto-detected: spatie/laravel-pdf → barryvdh/laravel-dompdf → dompdf/dompdf. Only DECLARED fields are read from requests (undeclared keys never reach the store/renderer).

## UUID / ULID Host Models

The published migration types `team_id` as `unsignedBigInteger`. If the
referenced model uses UUIDs or ULIDs, publish
`--tag=kinetix-pdf-migrations` and retype those columns
(`$table->uuid(…)` / `$table->ulid(…)`) BEFORE `php artisan migrate` —
type each column after the model it points to. Full recipe: the
`kinetix-boost` skill, section "UUID / ULID Host Models".
