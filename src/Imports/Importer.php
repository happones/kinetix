<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportColumnData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

abstract class Importer
{
    /**
     * The Eloquent model this importer writes to.
     */
    protected static ?string $model = null;

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
