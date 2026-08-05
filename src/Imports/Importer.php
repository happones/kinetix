<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportColumnData;
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
