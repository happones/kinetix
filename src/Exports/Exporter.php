<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

abstract class Exporter
{
    /**
     * The Eloquent model exported by default.
     */
    protected static ?string $model = null;

    /**
     * A signed token identifying this exporter class, safe to send to the
     * frontend (used by ExportAction to hit the export-start endpoint).
     */
    public static function token(): string
    {
        return Crypt::encryptString(static::class);
    }

    /**
     * Resolve an exporter instance from a signed token, validating the class.
     */
    public static function fromToken(string $token): self
    {
        $class = Crypt::decryptString($token);

        if (! class_exists($class) || ! is_subclass_of($class, self::class)) {
            throw new RuntimeException('Invalid exporter token.');
        }

        return new $class;
    }

    /**
     * Define the columns written to the export file.
     *
     * @return array<int, ExportColumn>
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
     * The query whose records are exported. Override to filter/scope the export.
     */
    public function query(): Builder
    {
        $model = static::getModel();

        return $model::query();
    }

    /**
     * The query actually exported: {@see query()} automatically narrowed to the
     * selected `ids` when the export was triggered from a bulk action. This is
     * what the processor runs, so a bulk export of N selected rows exports
     * exactly those N — no need to read `parameter('ids')` in your `query()`.
     */
    public function resolveExportQuery(): Builder
    {
        $query = $this->query();

        /** @var array<int, mixed> $ids */
        $ids = (array) $this->parameter('ids', []);

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        return $query;
    }

    /**
     * The download file name shown to the user (without extension).
     */
    public function fileName(): string
    {
        return (string) str(class_basename(static::class))->kebab();
    }

    /**
     * Output format: 'csv' or 'xlsx'.
     */
    public function format(): string
    {
        return 'csv';
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function queue(): ?string
    {
        return null;
    }

    /**
     * The header row (column headings).
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_map(fn (ExportColumn $column) => $column->getHeading(), static::getColumns());
    }

    /**
     * Map a record to a row of cell values.
     *
     * @return array<int, mixed>
     */
    public function mapRecord(Model $record): array
    {
        return array_map(fn (ExportColumn $column) => $column->getState($record), static::getColumns());
    }

    /**
     * Runtime parameters passed to the queued export (e.g. selected `ids`,
     * filters). Read them in {@see query()} via {@see parameter()}.
     *
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function withParameters(array $parameters): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Read a runtime parameter (set via {@see withParameters()} / {@see export()}).
     */
    public function parameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Dispatch the queued export. The recipient receives a download notification.
     * `$parameters` (e.g. `['ids' => [...]]`) reach the exporter inside the job.
     *
     * @param array<string, mixed> $parameters
     */
    public function export(?Model $recipient = null, array $parameters = []): void
    {
        $pending = ExportProcessor::dispatch(
            static::class,
            $recipient !== null ? $recipient::class : null,
            $recipient?->getKey(),
            $parameters !== [] ? $parameters : $this->parameters,
        );

        // Only pin a specific queue when the exporter defines one; otherwise use
        // the connection's default queue. (Passing config('queue.default') here
        // is the connection NAME, not a queue — Horizon would never pick it up.)
        if (($queue = $this->queue()) !== null) {
            $pending->onQueue($queue);
        }
    }
}
