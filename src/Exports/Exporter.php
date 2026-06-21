<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

abstract class Exporter
{
    /**
     * The Eloquent model exported by default.
     */
    protected static ?string $model = null;

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
     * Dispatch the queued export. The recipient receives a download notification.
     */
    public function export(?Model $recipient = null): void
    {
        ExportProcessor::dispatch(
            static::class,
            $recipient !== null ? $recipient::class : null,
            $recipient?->getKey(),
        )->onQueue($this->queue() ?? config('queue.default'));
    }
}
