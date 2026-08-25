<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportColumnData;
use Happones\Kinetix\Data\ImportSettingsData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

abstract class Importer
{
    /**
     * The Eloquent model this importer writes to.
     */
    protected static ?string $model = null;

    /**
     * Request-captured context restored on the queued worker (see context()).
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Whether the import modal offers a "Download template" link — a CSV whose
     * headers are this importer's column labels (they auto-map on upload).
     */
    protected bool $downloadableTemplate = true;

    /**
     * Filename for the downloaded template. Null = a studly of the importer
     * class name (`ProductImporter` → `ProductImporter.csv`).
     */
    protected ?string $templateFileName = null;

    /**
     * Whether the import dialog renders the sample-data preview table. Null
     * inherits `kinetix.imports.preview`; set false on importers whose files
     * are wide or whose cells are sensitive enough that showing them in the
     * dialog is undesirable.
     */
    protected ?bool $preview = null;

    /**
     * Data rows sampled for the preview — and the reader's ceiling, so this is
     * literally how much of the file is parsed to build it. Null inherits
     * `kinetix.imports.preview_rows`.
     */
    protected ?int $previewRows = null;

    /**
     * Source columns the preview table shows before the rest collapse behind a
     * "show all columns" toggle (0 = no cap). Null inherits
     * `kinetix.imports.preview_columns`.
     */
    protected ?int $previewColumns = null;

    /**
     * Dialog surface: 'auto' (a full-screen modal once the file exceeds
     * {@see getFullscreenThreshold()} columns), 'modal', 'fullscreen' or
     * 'sheet'. Null inherits `kinetix.imports.layout`.
     */
    protected ?string $layout = null;

    /**
     * Source-column count above which the 'auto' layout goes full screen. Null
     * inherits `kinetix.imports.fullscreen_threshold`.
     */
    protected ?int $fullscreenThreshold = null;

    /**
     * Upload ceiling in kilobytes for this importer's files. Null inherits
     * `kinetix.imports.max_upload_size`. PHP's own upload_max_filesize /
     * post_max_size still cap it.
     */
    protected ?int $maxUploadSize = null;

    /**
     * Define the target columns the file can be mapped onto.
     *
     * @return array<int, ImportColumn>
     */
    abstract public static function getColumns(): array;

    public static function getModel(): string
    {
        if (static::$model === null) {
            throw new RuntimeException('Static property $model must be set on '.static::class);
        }

        return static::$model;
    }

    /**
     * A signed token identifying this importer class, safe to send to the frontend.
     */
    public static function token(): string
    {
        return Crypt::encryptString(static::class);
    }

    /**
     * Resolve an importer instance from a signed token, validating the class.
     */
    public static function fromToken(string $token): self
    {
        $class = Crypt::decryptString($token);

        if (! class_exists($class) || ! is_subclass_of($class, self::class)) {
            throw new RuntimeException('Invalid importer token.');
        }

        return new $class;
    }

    /**
     * Policy ability required to run this import. Null resolves to `create`
     * whenever the target model has a policy; return a string to require a
     * different ability, or override {@see authorize()} for custom logic.
     */
    public function ability(): ?string
    {
        return null;
    }

    /**
     * Whether the given user may run this import.
     *
     * Enforced on every import endpoint (upload, preview, start), because an
     * import is a write primitive: it creates and updates records of the target
     * model. Without a policy on that model nothing is enforced here and the
     * host owns access.
     */
    public function authorize(?Authenticatable $user): bool
    {
        $ability = $this->ability()
            ?? (Gate::getPolicyFor(static::getModel()) !== null ? 'create' : null);

        if ($ability === null) {
            return true;
        }

        return Gate::forUser($user)->allows($ability, static::getModel());
    }

    /**
     * Number of rows processed per queued chunk.
     */
    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * The queue connection/name the import job is dispatched on (null = default).
     */
    public function queue(): ?string
    {
        return null;
    }

    /**
     * Toast shown the moment the import is queued. Override to customize
     * the message.
     */
    public function getStartedNotificationBody(): string
    {
        return (string) __('kinetix.import_started');
    }

    /**
     * Title of the completion notification. `$failed` counts rows skipped
     * because they failed validation or `importRow()` threw.
     */
    public function getCompletedNotificationTitle(int $imported, int $failed): string
    {
        return (string) __('kinetix.import_complete');
    }

    /**
     * Body of the completion notification. Per-row failure details are
     * appended automatically by the import job after this summary line.
     */
    public function getCompletedNotificationBody(int $imported, int $failed): string
    {
        return (string) __('kinetix.import_complete_body', [
            'imported' => $imported,
            'failed'   => $failed,
        ]);
    }

    /**
     * Title of the notification sent when the whole import job fails
     * (unreadable file, DB outage — after all retries are exhausted).
     */
    public function getFailedNotificationTitle(): string
    {
        return (string) __('kinetix.import_failed_title');
    }

    public function getFailedNotificationBody(): string
    {
        return (string) __('kinetix.import_failed_body');
    }

    /**
     * Whether the dialog shows the sample-data preview table.
     */
    public function hasPreview(): bool
    {
        return $this->preview ?? (bool) config('kinetix.imports.preview', true);
    }

    /**
     * Data rows sampled for the preview. Also the read ceiling: the file
     * reader stops here, so this is the whole cost of previewing a file
     * regardless of how many rows it actually has.
     */
    public function getPreviewRows(): int
    {
        return max(1, $this->previewRows ?? (int) config('kinetix.imports.preview_rows', 10));
    }

    /**
     * Source columns the preview table renders before the rest collapse behind
     * a "show all columns" toggle. 0 = no cap.
     */
    public function getPreviewColumns(): int
    {
        return max(0, $this->previewColumns ?? (int) config('kinetix.imports.preview_columns', 8));
    }

    /**
     * The dialog surface: 'auto' | 'modal' | 'fullscreen' | 'sheet'.
     */
    public function getLayout(): string
    {
        $layout = $this->layout ?? (string) config('kinetix.imports.layout', 'auto');

        return in_array($layout, ['auto', 'modal', 'fullscreen', 'sheet'], true) ? $layout : 'auto';
    }

    /**
     * Source-column count above which the 'auto' layout goes full screen.
     */
    public function getFullscreenThreshold(): int
    {
        return max(1, $this->fullscreenThreshold ?? (int) config('kinetix.imports.fullscreen_threshold', 12));
    }

    /**
     * Upload ceiling in kilobytes.
     */
    public function getMaxUploadSize(): int
    {
        return max(1, $this->maxUploadSize ?? (int) config('kinetix.imports.max_upload_size', 102400));
    }

    /**
     * The resolved dialog/reader settings sent to the frontend — carried by the
     * `open-importer` event (so the shell can size itself before a file
     * exists) and by every preview payload.
     */
    public function settings(): ImportSettingsData
    {
        return new ImportSettingsData(
            hasPreview: $this->hasPreview(),
            previewRows: $this->getPreviewRows(),
            previewColumns: $this->getPreviewColumns(),
            layout: $this->getLayout(),
            fullscreenThreshold: $this->getFullscreenThreshold(),
            maxUploadSize: $this->getMaxUploadSize(),
            template: $this->hasDownloadableTemplate() ? $this->getTemplateFileName() : null,
        );
    }

    public function hasDownloadableTemplate(): bool
    {
        return $this->downloadableTemplate;
    }

    public function getTemplateFileName(): string
    {
        return $this->templateFileName
            ?? str(class_basename(static::class))->studly()->append('.csv')->toString();
    }

    /**
     * The template's header row: the column labels, which auto-map on upload.
     *
     * @return array<int, string>
     */
    public function getTemplateHeaders(): array
    {
        return array_map(fn (ImportColumn $column): string => $column->getLabel(), static::getColumns());
    }

    /**
     * Capture request context to carry into the queued job — the worker has no
     * request, so anything tenant-scoped (current team, acting user, …) must be
     * captured here. The returned array is serialized with the job and restored
     * on the worker instance before any importRow() call; read it back via
     * `$this->context` or getContext().
     *
     *     public function context(Request $request): array
     *     {
     *         return ['team_id' => $request->user()?->currentTeam?->getKey()];
     *     }
     *
     * @return array<string, mixed> serializable values only
     */
    public function context(Request $request): array
    {
        return [];
    }

    /**
     * Restore captured context on this instance (called by the queued job).
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * The context captured at dispatch time (empty when dispatched without one).
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Resolve an existing record for upsert behaviour. Return null to always insert.
     *
     * @param array<string, mixed> $data
     */
    public function resolveRecord(array $data): ?Model
    {
        return null;
    }

    /**
     * Import a single mapped row (column name => value).
     *
     * @param array<string, mixed> $data
     */
    public function importRow(array $data): void
    {
        $model  = static::getModel();
        $record = $this->resolveRecord($data) ?? new $model;

        foreach (static::getColumns() as $column) {
            $name = $column->getName();

            if (! array_key_exists($name, $data)) {
                continue;
            }

            $value = $column->castState($data[$name]);

            if (! $column->fillRecord($record, $value, $data)) {
                $record->setAttribute($name, $value);
            }
        }

        $record->save();
    }

    /**
     * Build a collision-free automatic mapping of target columns to header indices.
     *
     * Each target column claims the first header (by name/alias match) that no
     * earlier column has already claimed, so one source column is never reused.
     *
     * @param  array<int, string>      $headers
     * @return array<string, int|null> column name => header index (or null when unmatched)
     */
    public static function guessMapping(array $headers): array
    {
        $mapping           = [];
        $usedHeaderIndexes = [];

        foreach (static::getColumns() as $column) {
            $matchedIndex = null;

            foreach ($headers as $index => $header) {
                if (in_array($index, $usedHeaderIndexes, true)) {
                    continue;
                }

                if ($column->matchesHeader($header)) {
                    $matchedIndex        = $index;
                    $usedHeaderIndexes[] = $index;
                    break;
                }
            }

            $mapping[$column->getName()] = $matchedIndex;
        }

        return $mapping;
    }

    /**
     * Whether the uploaded file lines up with this importer one-for-one: every
     * target column found a header, and every (non-blank) header was claimed.
     *
     * A file produced from the downloadable template always satisfies this,
     * since the template's header row IS the column labels — which is what
     * lets the dialog report "matches the template" and take the user straight
     * to review instead of making them confirm a mapping it already knows.
     *
     * @param array<int, string>      $headers
     * @param array<string, int|null> $mapping column name => header index
     */
    public static function isExactMatch(array $headers, array $mapping): bool
    {
        $namedHeaders = array_filter(
            $headers,
            fn (string $header): bool => ImportColumn::normalize($header) !== ''
        );

        if ($namedHeaders === []) {
            return false;
        }

        $mapped = array_filter($mapping, fn (?int $index): bool => $index !== null);

        return count($mapped) === count(static::getColumns())
            && count($mapped) === count($namedHeaders);
    }

    /**
     * Serialize the importer's columns for the frontend.
     *
     * @return array<int, ImportColumnData>
     */
    public static function getColumnsData(): array
    {
        return array_map(fn (ImportColumn $column) => $column->toData(), static::getColumns());
    }

    /**
     * The required target columns that must be mapped before import can start.
     *
     * @return array<int, string>
     */
    public static function getRequiredColumns(): array
    {
        $required = [];

        foreach (static::getColumns() as $column) {
            if ($column->isRequiredMapping()) {
                $required[] = $column->getName();
            }
        }

        return $required;
    }
}
