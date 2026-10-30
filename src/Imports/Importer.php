<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportColumnData;
use Happones\Kinetix\Data\ImportModeData;
use Happones\Kinetix\Data\ImportSettingsData;
use Happones\Kinetix\Support\Concerns\CarriesQueueContext;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

abstract class Importer
{
    use CarriesQueueContext;

    /**
     * The Eloquent model this importer writes to.
     */
    protected static ?string $model = null;

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
     * Validate every row with the class the screen validates with, as well as
     * the column rules and {@see rules()}: a FormRequest, a Form class or a
     * Resource (its form()). See {@see RowValidatorSource} for how each runs
     * on the queue worker.
     *
     * @var class-string|null
     */
    protected ?string $validateUsing = null;

    /**
     * Whether each row runs in its own savepoint, so a row that fails half
     * way leaves nothing behind (and re-uploading the failed-rows file is
     * always safe). Null inherits `kinetix.imports.transaction_per_row`.
     */
    protected ?bool $transactionPerRow = null;

    /**
     * What the import may do with the records already there, offered as a
     * choice in the dialog ({@see ImportMode}). Empty = no choice, rows go
     * through resolveRecord() as they always have. One mode = applied without
     * asking.
     *
     * @var array<int, ImportMode>
     */
    protected array $importModes = [];

    /**
     * The mode preselected in the dialog. Null = the first of $importModes.
     */
    protected ?ImportMode $defaultImportMode = null;

    /**
     * The columns that identify the existing record a row is about — `['sku']`,
     * `['team_id', 'code']` — looked up in {@see query()}. A row missing any
     * of them matches nothing. Override resolveRecord() for anything else.
     *
     * @var array<int, string>
     */
    protected array $matchBy = [];

    /**
     * The boolean column Replace sets to false on records missing from the
     * file. Null = soft deletes when the model uses them, otherwise an
     * `active` or `is_active` column when the table has one.
     */
    protected ?string $activeColumn = null;

    /**
     * The user who started the import (set on the worker), for
     * {@see getUser()} and a FormRequest's `$this->user()`.
     */
    protected ?Authenticatable $user = null;

    /**
     * @var array<int, ImportColumn>|null
     */
    private ?array $columns = null;

    /**
     * Memo for {@see record()}, live only while {@see processRow()} runs.
     */
    private bool $processingRow = false;

    private bool $rowRecordResolved = false;

    private ?Model $rowRecord = null;

    /**
     * @var array<string, bool>
     */
    private array $overrides = [];

    /**
     * The mode this run imports in (set on the worker), null when the
     * importer offers none.
     */
    private ?ImportMode $mode = null;

    /**
     * Replace: the keys of the records active when the import began, and of
     * those a row of the file matched — the difference is deactivated at the end.
     *
     * @var array<int|string, true>
     */
    private array $activeBefore = [];

    /**
     * @var array<int|string, true>
     */
    private array $kept = [];

    /**
     * One resolver per relationship column, for the whole import.
     *
     * @var array<string, RelatedRecordResolver>
     */
    private array $relatedResolvers = [];

    /**
     * @var array<string, BelongsTo<Model, Model>|BelongsToMany<Model, Model>>
     */
    private array $relations = [];

    /**
     * @var 'soft-delete'|'column'|null|false false = not resolved yet
     */
    private string|null|false $deactivation = false;

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
     * A signed token identifying this importer class, safe to send to the
     * frontend. Bound to the user, team and expiry it was minted with
     * ({@see SignedDescriptor}).
     */
    public static function token(): string
    {
        return SignedDescriptor::seal(['class' => static::class]);
    }

    /**
     * Resolve an importer instance from a signed token, validating the class
     * and the token's binding to the current request.
     */
    public static function fromToken(string $token): self
    {
        $class = SignedDescriptor::classFrom($token, self::class);

        if ($class === null) {
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
            template: $this->hasDownloadableTemplate() ? $this->getTemplateDownloadName() : null,
            modes: $this->importModeOptions(),
            defaultMode: $this->getDefaultImportMode()?->value,
        );
    }

    /**
     * The modes this importer offers, checked against what the model can do.
     *
     * @throws LogicException when a mode can't work for this importer
     *
     * @return array<int, ImportMode>
     */
    public function getImportModes(): array
    {
        foreach ($this->importModes as $mode) {
            if ($mode->requiresMatching() && $this->matchBy === [] && ! $this->overrides('resolveRecord')) {
                throw new LogicException(static::class." offers the {$mode->name} import mode but has no way to match a row to its record: set \$matchBy or override resolveRecord().");
            }

            if ($mode === ImportMode::Replace && $this->deactivationStrategy() === null) {
                throw new LogicException(static::class.' offers the Replace import mode but '.static::getModel().' can\'t be deactivated: use SoftDeletes, add an `active` column or set $activeColumn.');
            }
        }

        return array_values($this->importModes);
    }

    /**
     * The mode the dialog preselects (null when the importer offers none).
     */
    public function getDefaultImportMode(): ?ImportMode
    {
        if ($this->importModes === []) {
            return null;
        }

        return in_array($this->defaultImportMode, $this->importModes, true)
            ? $this->defaultImportMode
            : $this->importModes[0];
    }

    /**
     * The dialog's label for a mode. Override to say it in the model's terms
     * ("Replace the customer list").
     */
    public function getImportModeLabel(ImportMode $mode): string
    {
        return $mode->label();
    }

    public function getImportModeDescription(ImportMode $mode): string
    {
        return $mode->description();
    }

    /**
     * Whether the given user may run the import in this mode — checked when
     * the import starts, after {@see authorize()}. Replace deactivates
     * records, so an app may want more than `create` for it:
     *
     *     return $mode !== ImportMode::Replace || $user?->can('deleteAny', Customer::class);
     */
    public function authorizeMode(?Authenticatable $user, ImportMode $mode): bool
    {
        return true;
    }

    /**
     * @return array<int, ImportModeData>
     */
    protected function importModeOptions(): array
    {
        return array_map(fn (ImportMode $mode): ImportModeData => new ImportModeData(
            value: $mode->value,
            label: $this->getImportModeLabel($mode),
            description: $this->getImportModeDescription($mode),
            destructive: $mode === ImportMode::Replace,
        ), $this->getImportModes());
    }

    /**
     * Set the mode this run imports in (the queued job does this).
     */
    public function withMode(?ImportMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode(): ?ImportMode
    {
        return $this->mode;
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
     * The template's filename as downloaded: {@see getTemplateFileName()},
     * with `.csv` added when an override left the extension off.
     */
    final public function getTemplateDownloadName(): string
    {
        $name = $this->getTemplateFileName();

        return str_ends_with(strtolower($name), '.csv') ? $name : $name.'.csv';
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
     * Resolve the existing record a row is about (null = the row creates
     * one). By default it looks the row's {@see $matchBy} columns up in
     * {@see query()} — soft-deleted records included in Replace, which brings
     * them back; with no $matchBy every row creates.
     *
     * Return null when nothing matches and build new records in importRow():
     * import modes count a row as matched only when this returns a stored
     * record (an unsaved model returned here counts as no match).
     *
     * @param array<string, mixed> $data
     */
    public function resolveRecord(array $data): ?Model
    {
        if ($this->matchBy === []) {
            return null;
        }

        $query = $this->query();

        if ($this->mode === ImportMode::Replace && $this->deactivationStrategy() === 'soft-delete') {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        foreach ($this->matchBy as $column) {
            $value = $data[$column] ?? null;

            // A blank key matches nothing — `where(col, null)` would be a
            // whereNull and pick an unrelated record.
            if ($value === null || $value === '') {
                return null;
            }

            $query->where($query->qualifyColumn($column), $value);
        }

        return $query->first();
    }

    /**
     * The records this importer may touch: where {@see $matchBy} looks rows
     * up and what Replace deactivates. A multi-tenant app MUST scope it with
     * the captured context — the worker has no request, and an unscoped
     * Replace deactivates every tenant's records:
     *
     *     protected function query(): Builder
     *     {
     *         return Customer::query()->where('team_id', $this->context['team_id']);
     *     }
     *
     * @return Builder<Model>
     */
    protected function query(): Builder
    {
        return static::getModel()::query();
    }

    /**
     * How Replace deactivates: 'soft-delete', 'column' ({@see $activeColumn})
     * or null when the model can't be deactivated.
     *
     * @return 'soft-delete'|'column'|null
     */
    protected function deactivationStrategy(): ?string
    {
        if ($this->deactivation !== false) {
            return $this->deactivation;
        }

        if ($this->activeColumn === null && in_array(SoftDeletes::class, class_uses_recursive(static::getModel()), true)) {
            return $this->deactivation = 'soft-delete';
        }

        return $this->deactivation = $this->activeColumn() !== null ? 'column' : null;
    }

    /**
     * The active flag Replace writes to: $activeColumn, else `active` or
     * `is_active` when the table has one.
     */
    protected function activeColumn(): ?string
    {
        if ($this->activeColumn !== null) {
            return $this->activeColumn;
        }

        $model = new (static::getModel());
        $table = $model->getConnection()->getSchemaBuilder();

        foreach (['active', 'is_active'] as $column) {
            if ($table->hasColumn($model->getTable(), $column)) {
                return $this->activeColumn = $column;
            }
        }

        return null;
    }

    /**
     * Deactivate the records Replace found missing from the file — one call
     * per batch of keys. Runs as a query (no model events); override to
     * deactivate another way.
     *
     * @param  Builder<Model> $records
     * @return int            records deactivated
     */
    protected function deactivate(Builder $records): int
    {
        if ($this->deactivationStrategy() === 'soft-delete') {
            return (int) $records->delete();
        }

        return $records->update([(string) $this->activeColumn() => false]);
    }

    /**
     * Before the first row (the queued job calls it): Replace remembers which
     * records are active now, so the ones the file creates are never swept.
     */
    public function beginImport(): void
    {
        $this->activeBefore = [];
        $this->kept         = [];

        if ($this->mode !== ImportMode::Replace) {
            return;
        }

        $query = $this->query();

        if ($this->deactivationStrategy() === 'column') {
            $query->where($query->qualifyColumn((string) $this->activeColumn()), true);
        }

        $model = $query->getModel();

        foreach ($query->toBase()->lazyById(2000, $model->getQualifiedKeyName(), $model->getKeyName()) as $row) {
            $this->activeBefore[$row->{$model->getKeyName()}] = true;
        }
    }

    /**
     * After the last row (the queued job calls it): Replace deactivates the
     * records that were active before and that no row of the file matched.
     * Skipped when nothing was imported — a file whose every row failed (a
     * wrong mapping, say) must not empty the list.
     *
     * @return int records deactivated
     */
    public function finishImport(int $imported): int
    {
        if ($this->mode !== ImportMode::Replace || $imported === 0) {
            return 0;
        }

        $missing = array_keys(array_diff_key($this->activeBefore, $this->kept));

        $this->activeBefore = [];
        $this->kept         = [];

        return DB::transaction(function () use ($missing): int {
            $deactivated = 0;

            foreach (array_chunk($missing, 1000) as $keys) {
                $deactivated += $this->deactivate($this->query()->whereKey($keys));
            }

            return $deactivated;
        });
    }

    /**
     * Whether each row runs in its own savepoint ({@see $transactionPerRow}).
     */
    public function usesTransactionPerRow(): bool
    {
        return $this->transactionPerRow ?? (bool) config('kinetix.imports.transaction_per_row', true);
    }

    /**
     * Set the user who started the import (the queued job does this).
     */
    public function forUser(?Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?Authenticatable
    {
        return $this->user;
    }

    /**
     * Normalize a mapped row before it is validated and imported — the
     * importer's prepareForValidation(). Turn "15/03/1990" into "1990-03-15"
     * or "Sí" into true here and the rules check the value that is stored.
     * (castStateUsing() runs later, on the validated value.)
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function prepareRow(array $row): array
    {
        return $row;
    }

    /**
     * Rules for the row, added to each mapped column's ->rules(). Unlike
     * those, they see the row, the captured context and the record the row
     * updates (null when it creates one):
     *
     *     return [
     *         'email'    => ['nullable', 'email', Rule::unique('employees')->ignore($record)],
     *         'username' => ['nullable', Rule::unique('employees')
     *             ->where('team_id', $this->context['team_id'])
     *             ->ignore($record)],
     *     ];
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function rules(array $row, ?Model $record): array
    {
        return [];
    }

    /**
     * Custom validation messages, as a FormRequest's messages().
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * `:attribute` names. Each column's label is the default, so a message
     * names the header the user sees in their file.
     *
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [];
    }

    /**
     * Checks across fields, run after the rules — each a callable receiving
     * the validator, as a FormRequest's after():
     *
     *     return [function (Validator $validator) use ($row): void {
     *         if (($row['sdi'] ?? 0) < ($row['salary'] ?? 0)) {
     *             $validator->errors()->add('sdi', 'The SDI can't be below the salary.');
     *         }
     *     }];
     *
     * @param  array<string, mixed> $row
     * @return array<int, callable>
     */
    protected function after(array $row, ?Model $record): array
    {
        return [];
    }

    /**
     * Turn the current row down: it counts as failed, with this reason.
     */
    protected function reject(string $reason): never
    {
        throw new RowRejected($reason);
    }

    /**
     * The record the current row updates (null = it creates one), resolved
     * through {@see resolveRecord()} ONCE per row and shared by rules(),
     * after() and importRow(). A custom importRow() reads it from here
     * instead of resolving it again.
     *
     * @param array<string, mixed> $data
     */
    protected function record(array $data): ?Model
    {
        if (! $this->processingRow) {
            return $this->resolveRecord($data);
        }

        if (! $this->rowRecordResolved) {
            $this->rowRecord         = $this->resolveRecord($data);
            $this->rowRecordResolved = true;
        }

        return $this->rowRecord;
    }

    /**
     * Prepare, validate and import one mapped row — what the queued job runs
     * for each row of the file. Throws a ValidationException, a RowRejected
     * or whatever importRow() throws when the row fails.
     *
     * @param array<string, mixed> $row column name => raw cell, mapped columns only
     */
    public function processRow(array $row): void
    {
        $this->processingRow     = true;
        $this->rowRecordResolved = false;
        $this->rowRecord         = null;

        foreach ($this->relatedResolvers as $resolver) {
            $resolver->beginRow();
        }

        try {
            $mapped = array_keys($row);
            $row    = $this->prepareRow($row);

            $this->applyMode($row);

            $row = $this->validateRow($row, $mapped);
            $row = $this->resolveRelationships($row);

            $this->importRow($row);
        } catch (Throwable $e) {
            foreach ($this->relatedResolvers as $resolver) {
                $resolver->rollbackRow();
            }

            throw $e;
        } finally {
            $this->processingRow     = false;
            $this->rowRecordResolved = false;
            $this->rowRecord         = null;
        }
    }

    /**
     * Resolve a chunk's relationship cells in a few batched queries before its
     * rows run (the queued job calls it) — without it every row looks its
     * related records up on its own.
     *
     * @param array<int, array<string, mixed>> $rows mapped rows
     */
    public function warmRelationships(array $rows): void
    {
        foreach ($this->columns() as $column) {
            if (! $column->isRelationship()) {
                continue;
            }

            $name  = $column->getName();
            $cells = [];

            foreach ($rows as $row) {
                if (! array_key_exists($name, $row)) {
                    continue;
                }

                try {
                    $cells[] = $column->castState($row[$name]);
                } catch (Throwable) {
                    // The row reports it when it runs.
                }
            }

            if ($cells !== []) {
                $this->relatedResolver($column)->warm($cells);
            }
        }
    }

    /**
     * Swap each relationship cell for the related key (keys, for a
     * multiple() column), after the row validated. castStateUsing() runs on
     * the cell first, so it can clean up what is looked up.
     *
     * @param array<string, mixed> $row
     *
     * @throws ValidationException when a cell matches nothing or several records
     *
     * @return array<string, mixed>
     */
    protected function resolveRelationships(array $row): array
    {
        foreach ($this->columns() as $column) {
            if ($column->isRelationship() && array_key_exists($column->getName(), $row)) {
                $row[$column->getName()] = $this->relatedResolver($column)->resolve($column->castState($row[$column->getName()]));
            }
        }

        return $row;
    }

    protected function relatedResolver(ImportColumn $column): RelatedRecordResolver
    {
        return $this->relatedResolvers[$column->getName()] ??= new RelatedRecordResolver(
            $this->relation($column)->getRelated(),
            $column,
            $this->context,
        );
    }

    /**
     * The owning model's relation a column fills.
     *
     * @throws LogicException for anything but a BelongsTo / BelongsToMany
     *
     * @return BelongsTo<Model, Model>|BelongsToMany<Model, Model>
     */
    protected function relation(ImportColumn $column): BelongsTo|BelongsToMany
    {
        return $this->relations[$column->getName()] ??= $this->makeRelation($column);
    }

    /**
     * @return BelongsTo<Model, Model>|BelongsToMany<Model, Model>
     */
    private function makeRelation(ImportColumn $column): BelongsTo|BelongsToMany
    {
        $name  = (string) $column->getRelationship();
        $owner = new (static::getModel());

        $relation = method_exists($owner, $name) ? $owner->{$name}() : null;

        if (! $relation instanceof BelongsTo && ! $relation instanceof BelongsToMany) {
            throw new LogicException(static::class." imports the column [{$column->getName()}] into [{$name}], which isn't a BelongsTo or BelongsToMany relation of ".static::getModel().'.');
        }

        return $relation;
    }

    /**
     * Sync the BelongsToMany columns of a row onto its saved record. The
     * default importRow() calls it after saving; a custom importRow() calls
     * it the same way.
     *
     * @param array<string, mixed> $data
     */
    protected function syncRelationships(Model $record, array $data): void
    {
        foreach ($this->columns() as $column) {
            $name = $column->getName();

            if (! $column->isRelationship() || ! array_key_exists($name, $data) || $this->relation($column) instanceof BelongsTo) {
                continue;
            }

            if (! $column->fillRecord($record, $data[$name], $data)) {
                $record->{(string) $column->getRelationship()}()->sync((array) $data[$name]);
            }
        }
    }

    /**
     * Hold the row to the run's mode before it is validated: Create turns
     * down a row whose record exists, Update one whose record doesn't, and
     * Replace keeps (and reactivates) the record a row matches — even when
     * the row then fails, its record stays as it was rather than being swept.
     *
     * @param array<string, mixed> $row
     */
    protected function applyMode(array $row): void
    {
        if ($this->mode === null) {
            return;
        }

        // An unsaved model (a resolveRecord() that builds the new record) is
        // no match: only a stored record makes a row an update.
        $record = $this->record($row);
        $record = $record?->exists === true ? $record : null;

        if ($this->mode === ImportMode::Create && $record !== null) {
            $this->reject((string) __('kinetix.import_row_exists'));
        }

        if ($this->mode === ImportMode::Update && $record === null) {
            $this->reject((string) __('kinetix.import_row_not_found'));
        }

        if ($this->mode !== ImportMode::Replace || $record === null) {
            return;
        }

        $this->kept[$record->getKey()] = true;

        // Set, not saved: importRow()'s save brings the record back with the
        // row's values, and a row that fails leaves it untouched.
        if ($this->deactivationStrategy() === 'soft-delete') {
            $record->setAttribute(method_exists($record, 'getDeletedAtColumn') ? $record->getDeletedAtColumn() : 'deleted_at', null);
        } else {
            $record->setAttribute((string) $this->activeColumn(), true);
        }
    }

    /**
     * Validate a prepared row. Nothing runs (and no record is resolved) for
     * an importer with no rules at all.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $mapped the columns the file maps
     *
     * @throws ValidationException
     *
     * @return array<string, mixed> the row to import — a FormRequest's
     *                              prepareForValidation() may have changed it
     */
    protected function validateRow(array $row, array $mapped): array
    {
        $columnRules = $this->columnRules($mapped);
        $needsRecord = $this->validateUsing !== null || $this->overrides('rules') || $this->overrides('after');

        if ($columnRules === [] && ! $needsRecord) {
            return $row;
        }

        $record = $needsRecord ? $this->record($row) : null;

        [$validator, $row] = $this->validateUsing !== null
            ? RowValidatorSource::make($this->validateUsing, $row, $record, static::getModel(), $this->user)
            : [ValidatorFacade::make($row, []), $row];

        // Added one set at a time so a field's rules from both accumulate.
        $validator->addRules($columnRules);
        $validator->addRules($this->rules($row, $record));
        $validator->setCustomMessages($this->messages());
        $validator->addCustomAttributes([...$this->columnLabels(), ...$this->attributes()]);
        $validator->after($this->after($row, $record));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $row;
    }

    /**
     * The rules of the mapped columns that declare any.
     *
     * @param  array<int, string>               $mapped
     * @return array<string, array<int, mixed>>
     */
    public function columnRules(array $mapped): array
    {
        $rules = [];

        foreach ($this->columns() as $column) {
            if (in_array($column->getName(), $mapped, true) && $column->getRules() !== []) {
                $rules[$column->getName()] = $column->getRules();
            }
        }

        return $rules;
    }

    /**
     * Each column's label, as the template and the mapping step show it:
     * "The Nombre field is required.", not "The name field is required."
     *
     * @return array<string, string>
     */
    protected function columnLabels(): array
    {
        $labels = [];

        foreach ($this->columns() as $column) {
            if ($column->getLabel() !== '') {
                $labels[$column->getName()] = $column->getLabel();
            }
        }

        return $labels;
    }

    /**
     * The columns, built once per importer instance rather than per row.
     *
     * @return array<int, ImportColumn>
     */
    protected function columns(): array
    {
        return $this->columns ??= static::getColumns();
    }

    private function overrides(string $method): bool
    {
        return $this->overrides[$method] ??= (new ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Import a single mapped row (column name => value).
     *
     * @param array<string, mixed> $data
     */
    public function importRow(array $data): void
    {
        $model  = static::getModel();
        $record = $this->record($data) ?? new $model;

        foreach ($this->columns() as $column) {
            $name = $column->getName();

            if (! array_key_exists($name, $data)) {
                continue;
            }

            if ($column->isRelationship()) {
                $relation = $this->relation($column);

                // A BelongsToMany needs the saved record: syncRelationships().
                if ($relation instanceof BelongsTo && ! $column->fillRecord($record, $data[$name], $data)) {
                    $record->setAttribute($relation->getForeignKeyName(), $data[$name]);
                }

                continue;
            }

            $value = $column->castState($data[$name]);

            if (! $column->fillRecord($record, $value, $data)) {
                $record->setAttribute($name, $value);
            }
        }

        $record->save();

        $this->syncRelationships($record, $data);
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
