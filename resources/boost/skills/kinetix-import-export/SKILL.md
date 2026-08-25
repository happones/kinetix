---
name: kinetix-import-export
description: "Handles CSV/Excel import through a three-step wizard (file → mapping → review) and queued export with a download notification in Kinetix. Activates when building Importer/Exporter classes, ImportColumn/ExportColumn definitions, the import wizard UI, tuning the import preview/upload/layout limits, or the queued import/export jobs."
license: MIT
metadata:
  author: happones
---

# Kinetix Import / Export Development

## When to Apply

Activate this skill when:
- Building an `Importer` (CSV/Excel ingestion) or `Exporter` (CSV/Excel generation).
- Defining `ImportColumn` / `ExportColumn` mappings, validation rules, alias guesses, or value casting/formatting.
- Wiring the import wizard UI (`KinetixImporter.vue`, `components/Importer/*`, `useKinetixImporter`) or the import endpoints (`imports/upload|preview|start`).
- Tuning what the import dialog reads or shows: preview rows/columns, upload ceiling, dialog surface (modal / full-screen / sheet).
- Importing a large file (tens of thousands of rows and up) and reasoning about memory or read cost.
- Working with the queued `ImportProcessor` / `ExportProcessor` jobs or the export download route.
- Customizing the dispatched job (chunking, queue, per-row `importRow()`/`resolveRecord()`, scoped export `query()`).

## Documentation

Full reference: [Kinetix Import / Export Documentation](https://happones.github.io/kinetix/import-export).

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
<!-- Standalone on a page (the page scrolls). -->
<KinetixImporter :importer="importer" />
```

Or, with zero page wiring, from a table action — mount `<KinetixImportModal />`
once in your layout and the dialog picks its own surface:

```php
use Happones\Kinetix\Actions\ImportAction;

$table->headerActions([ImportAction::make()->importer(ContactImporter::class)]);
```

### 1b. Tuning the dialog per importer

```php
class WideProductImporter extends Importer
{
    protected ?int $previewRows = 5;              // sample rows — AND the read ceiling
    protected ?int $previewColumns = 0;           // 0 = no column cap in the preview
    protected ?string $layout = 'fullscreen';     // 'auto'|'modal'|'fullscreen'|'sheet'
    protected ?int $fullscreenThreshold = 8;      // when 'auto' goes full screen
    protected ?int $maxUploadSize = 512000;       // KB
}

class SensitiveImporter extends Importer
{
    protected ?bool $preview = false;             // map the columns, never show the cells
}
```

Everything falls back to `config('kinetix.imports.*')`
(`max_upload_size`, `preview`, `preview_rows`, `preview_columns`, `layout`,
`fullscreen_threshold`, `spreadsheet_chunk_size`).

### 2. Exporter + download notification

```php
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\ExportColumn;

class ContactExporter extends Exporter
{
    protected static ?string $model = \App\Models\Contact::class;

    public function format(): string { return 'xlsx'; } // 'csv' | 'xlsx' | 'pdf'

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
- **Scale is already handled — do not undo it (IMPORTANT)**: nothing in the import path may grow with the file. Previewing reads a SAMPLE (`FileReader::read($path, $options, $limit)` stops at the limit); the row count is counted, not parsed (CSV newline blocks; a spreadsheet's own `listWorksheetInfo()`); the queued job consumes `FileReader::stream()`, a generator, so it holds one `chunkSize()` chunk and never the file; failed rows are written to the downloadable CSV as they fail. **Never call `FileReader::read()` without a limit on a user-supplied file** and never collect rows into an array to process them — that is how a million-row import becomes a fatal memory error. Spreadsheets are read in windows of `spreadsheet_chunk_size` rows (`RowWindowFilter`), first worksheet only.
- **Exact matches skip a step**: `Importer::isExactMatch($headers, $mapping)` is true when every target column found a header AND every non-blank header was claimed — which a file filled in from the downloadable template always satisfies. The wizard then goes straight to review. It deliberately fails when a source column goes unclaimed, because that column's data would be silently dropped; the mapping step names those columns instead.
- **The dialog sizes itself, in one component**: under `layout: 'auto'` `KinetixImportModal` promotes the SAME `KinetixModal` to `fullscreen` past `fullscreenThreshold` columns, so the wizard is resized rather than remounted and the user's mapping survives. `'sheet'` is an explicit per-importer choice only — swapping shells mid-flow would unmount the wizard and lose the file.
- **`surface` decides who scrolls**: `inline` (the page), `modal` (the wizard's own capped scroller), `fullscreen` (its scroller fills the panel), `sheet` (none — `KinetixSheet` already scrolls). Pass it whenever you place `KinetixImporter` in a shell of your own.
- **Always queue heavy work**: both `ImportProcessor` and `ExportProcessor` are `ShouldQueue`. Process in chunked DB transactions (`chunkSize()`), then notify via the Kinetix `Notification` (`broadcast()` when Echo is configured, else `sendToDatabase()`).
- **Excel vs CSV vs PDF**: CSV is parsed/written natively (RFC-4180, empty escape); `.xls`/`.xlsx` go through `phpoffice/phpspreadsheet`; `pdf` renders a landscape-A4 HTML table via the **optional** `dompdf/dompdf` (install `composer require dompdf/dompdf`; throws a clear error if missing). All in `FileWriter` — add new formats there, not in the jobs.
- **Customizing**: override `importRow()`/`resolveRecord()` for upsert logic, `query()` to scope an export, and `queue()`/`chunkSize()` for throughput — do not bypass the job.
- **Notification lifecycle is automatic**: wiring `ExportAction::exporter()` / `ImportAction::importer()` gives a queued toast immediately, a completion database notification (broadcast live when Echo is configured) and a `danger` notification if the job dies. Rows that fail to map/validate/save are **skipped, counted and reported** (status `warning`) — never fatal — and a failed import additionally attaches a **Download failed rows** CSV action (every failed row + reason, signed user-bound link). Customize every message by overriding `getStartedNotificationBody()`, `getCompletedNotificationTitle/Body(int $done, int $failed)` and `getFailedNotificationTitle/Body()` on the exporter/importer. Completion notifications require `KINETIX_DATABASE_NOTIFICATIONS=true` + the `notifications` table + `<KinetixNotifications />` mounted (or broadcasting) to be visible; they're team-stamped automatically when notifications are team-scoped.
- **Template download**: the modal offers "Download template" by default (CSV of the column labels — they auto-map on upload). Opt out with `protected bool $downloadableTemplate = false`; rename with `protected ?string $templateFileName` (default = studly class name `.csv`). Endpoint `GET {prefix}/imports/template?importer={token}`.
- **Multi-tenancy (IMPORTANT)**: the queued job has no request — never infer the tenant inside `importRow()` (e.g. `Team::first()` is a cross-tenant leak). Override `context(Request $request): array` to capture `['team_id' => $request->user()?->currentTeam?->getKey()]` at dispatch; it is serialized with the job and restored before any row — read it via `$this->context['team_id']` / `getContext()`.
- **i18n & docs**: any new option/label must be added to every shipped locale under `resources/lang/*/kinetix.php` and to `docs/import-export.md`.
