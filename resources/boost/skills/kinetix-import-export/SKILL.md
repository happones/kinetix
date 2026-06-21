---
name: kinetix-import-export
description: "Handles CSV/Excel import with a smart mapping preview and queued export with a download notification in Kinetix. Activates when building Importer/Exporter classes, ImportColumn/ExportColumn definitions, the import preview UI, or the queued import/export jobs."
license: MIT
metadata:
  author: happones
---

# Kinetix Import / Export Development

## When to Apply

Activate this skill when:
- Building an `Importer` (CSV/Excel ingestion) or `Exporter` (CSV/Excel generation).
- Defining `ImportColumn` / `ExportColumn` mappings, validation rules, alias guesses, or value casting/formatting.
- Wiring the smart-preview UI (`KinetixImporter.vue`) or the import endpoints (`imports/upload|preview|start`).
- Working with the queued `ImportProcessor` / `ExportProcessor` jobs or the export download route.
- Customizing the dispatched job (chunking, queue, per-row `importRow()`/`resolveRecord()`, scoped export `query()`).

## Documentation

Full reference: [Kinetix Import / Export Documentation](file:///home/happones/Plugins/Php/kinetix/docs/import-export.md).

## Usage Guide

### 1. Importer + smart preview

```php
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\ImportColumn;

class ContactImporter extends Importer
{
    protected static ?string $model = \App\Models\Contact::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')->guess(['nombre'])->requiredMapping(),
            ImportColumn::make('email')->guess(['e-mail'])->rules(['email'])
                ->castStateUsing(fn ($v) => strtolower(trim((string) $v))),
            ImportColumn::make('phone')->guess(['celular', 'mobile']),
        ];
    }

    public function resolveRecord(array $data): ?\Illuminate\Database\Eloquent\Model
    {
        return \App\Models\Contact::firstOrNew(['email' => $data['email'] ?? null]);
    }
}
```

```php
// Controller → page
return inertia('Contacts/Import', ['importer' => ContactImporter::token()]);
```

```vue
<KinetixImporter :importer="importer" />
```

### 2. Exporter + download notification

```php
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\ExportColumn;

class ContactExporter extends Exporter
{
    protected static ?string $model = \App\Models\Contact::class;

    public function format(): string { return 'xlsx'; } // or 'csv'

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label('Full Name'),
            ExportColumn::make('email'),
        ];
    }
}

// Dispatch — the recipient receives an "Export ready" notification with a Download action.
(new ContactExporter())->export($request->user());
```

---

## Best Practices

- **Smart mapping is server-driven**: `Importer::guessMapping($headers)` matches headers against each column's name/label/`guess()` aliases (normalized: case/spacing/punctuation insensitive) and is **collision-free** — one source header maps to at most one target. The Vue layer only disables already-claimed options; never re-implement matching client-side.
- **Security**: the importer/exporter class travels as an encrypted `token()`; stored files are referenced by encrypted tokens constrained to `kinetix-imports` / `kinetix-exports` (traversal-guarded). Keep that pattern for any new endpoint.
- **Always queue heavy work**: both `ImportProcessor` and `ExportProcessor` are `ShouldQueue`. Process in chunked DB transactions (`chunkSize()`), then notify via the Kinetix `Notification` (`broadcast()` when Echo is configured, else `sendToDatabase()`).
- **Excel vs CSV**: CSV is parsed/written natively (RFC-4180, empty escape); `.xls`/`.xlsx` go through `phpoffice/phpspreadsheet` in `FileReader`/`FileWriter`. Add new formats there, not in the jobs.
- **Customizing**: override `importRow()`/`resolveRecord()` for upsert logic, `query()` to scope an export, and `queue()`/`chunkSize()` for throughput — do not bypass the job.
- **i18n & docs**: any new option/label must be added to `resources/lang/{en,es,fr,pt}/kinetix.php` and `docs/import-export.md`.
