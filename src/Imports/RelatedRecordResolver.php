<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;

/**
 * Turns the cells of a relationship column ({@see ImportColumn::relationship()})
 * into related keys.
 *
 * Lookups are batched: {@see warm()} resolves every distinct cell of a chunk in
 * one query per match column, and each answer — found, missing or ambiguous —
 * is cached for the rest of the import (bounded, so a file of a million
 * distinct values can't grow it without limit). A cell the chunk didn't warm
 * (prepareRow() changed it) is looked up on its own the same way.
 */
final class RelatedRecordResolver
{
    private const MAX_CACHED = 20000;

    private const LOOKUP_BATCH = 500;

    /**
     * Normalized cell => related key, false when it matches more than one
     * record, null when it matches none.
     *
     * @var array<string, int|string|false|null>
     */
    private array $resolved = [];

    /**
     * Cells whose record this row created — forgotten if the row fails, since
     * its savepoint rolls the record back.
     *
     * @var array<int, string>
     */
    private array $createdThisRow = [];

    /**
     * @var array<int, string>
     */
    private array $matchBy;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly Model $related,
        private readonly ImportColumn $column,
        private readonly array $context = [],
    ) {
        $this->matchBy = $column->getRelationshipMatchBy() !== []
            ? $column->getRelationshipMatchBy()
            : [$related->getKeyName()];
    }

    /**
     * Resolve the distinct cells of a chunk ahead of its rows.
     *
     * @param array<int, mixed> $cells
     */
    public function warm(array $cells): void
    {
        $values = [];

        foreach ($cells as $cell) {
            foreach ($this->split($cell) as $value) {
                $values[$this->normalize($value)] = $value;
            }
        }

        $this->lookup(array_diff_key($values, $this->resolved));
    }

    /**
     * The related key for a cell — a list of keys for a multiple() column.
     *
     * @throws ValidationException when a value matches nothing or several records
     *
     * @return int|string|array<int, int|string>|null
     */
    public function resolve(mixed $cell): int|string|array|null
    {
        $values = $this->split($cell);

        if ($values === []) {
            return $this->column->getMultipleSeparator() !== null ? [] : null;
        }

        $pending = [];

        foreach ($values as $value) {
            if (! array_key_exists($this->normalize($value), $this->resolved)) {
                $pending[$this->normalize($value)] = $value;
            }
        }

        $this->lookup($pending);

        $keys     = [];
        $messages = [];

        foreach ($values as $value) {
            $key = $this->resolved[$this->normalize($value)] ?? null;

            if ($key === null && $this->column->getCreateMissing() !== false) {
                $key = $this->create($value);
            }

            if ($key === false) {
                $messages[] = __('kinetix.import_related_ambiguous', ['attribute' => $this->column->getLabel(), 'value' => $value]);
            } elseif ($key === null) {
                $messages[] = __('kinetix.import_related_not_found', ['attribute' => $this->column->getLabel(), 'value' => $value]);
            } else {
                $keys[] = $key;
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages([$this->column->getName() => $messages]);
        }

        return $this->column->getMultipleSeparator() !== null ? array_values(array_unique($keys)) : $keys[0];
    }

    public function beginRow(): void
    {
        $this->createdThisRow = [];
    }

    /**
     * The row failed: what it created was rolled back with it.
     */
    public function rollbackRow(): void
    {
        foreach ($this->createdThisRow as $normalized) {
            unset($this->resolved[$normalized]);
        }

        $this->createdThisRow = [];
    }

    /**
     * Look up values not resolved yet, column by column in $matchBy order: a
     * value found by one column isn't tried against the next.
     *
     * @param array<string, string> $pending normalized => cell value
     */
    private function lookup(array $pending): void
    {
        if ($pending === []) {
            return;
        }

        if (count($this->resolved) + count($pending) > self::MAX_CACHED) {
            $this->resolved = [];
        }

        $keyName = $this->related->getKeyName();

        foreach ($this->matchBy as $column) {
            $isKey      = $column === $keyName;
            $candidates = $isKey ? array_filter($pending, $this->isValidKey(...)) : $pending;
            $found      = [];

            foreach (array_chunk($candidates, self::LOOKUP_BATCH, true) as $batch) {
                foreach ($this->query($column, $batch, $isKey)->get() as $row) {
                    $normalized = $this->normalize((string) $row->getAttribute($column));

                    if (isset($pending[$normalized])) {
                        $found[$normalized][(string) $row->getKey()] = $row->getKey();
                    }
                }
            }

            foreach ($found as $normalized => $keys) {
                $this->resolved[$normalized] = count($keys) === 1 ? reset($keys) : false;
                unset($pending[$normalized]);
            }

            if ($pending === []) {
                return;
            }
        }

        foreach (array_keys($pending) as $normalized) {
            $this->resolved[$normalized] = null;
        }
    }

    /**
     * @param  array<string, string> $batch normalized => cell value
     * @return Builder<Model>
     */
    private function query(string $column, array $batch, bool $isKey): Builder
    {
        $query = $this->related->newQuery();

        if (($scope = $this->column->getRelationshipQuery()) !== null) {
            $scope($query, $this->context);
        }

        $qualified = $query->qualifyColumn($column);
        $query->select([$this->related->getQualifiedKeyName(), $qualified]);

        if ($isKey || $this->column->isCaseSensitive()) {
            return $query->whereIn($qualified, array_values($batch));
        }

        // The exact values too: SQLite's LOWER() only folds ASCII, so "Ñandú"
        // must still find "Ñandú".
        $lower = new Expression('LOWER('.$query->getQuery()->getGrammar()->wrap($qualified).')');

        return $query->where(fn (Builder $match) => $match
            ->whereIn($qualified, array_values($batch))
            ->orWhereIn($lower, array_keys($batch)));
    }

    private function create(string $value): int|string
    {
        $createMissing = $this->column->getCreateMissing();

        $attributes = $createMissing instanceof \Closure
            ? (array) $createMissing($value, $this->context)
            : [$this->titleColumn() => $value];

        $record = $this->related->newInstance();
        $record->forceFill($attributes)->save();

        $normalized                  = $this->normalize($value);
        $this->resolved[$normalized] = $record->getKey();
        $this->createdThisRow[]      = $normalized;

        return $record->getKey();
    }

    /**
     * The column a created record's name goes in: the first non-key $matchBy.
     */
    private function titleColumn(): string
    {
        foreach ($this->matchBy as $column) {
            if ($column !== $this->related->getKeyName()) {
                return $column;
            }
        }

        return 'name';
    }

    /**
     * @return array<int, string>
     */
    private function split(mixed $cell): array
    {
        if ($cell === null || is_array($cell) || is_object($cell)) {
            return [];
        }

        $separator = $this->column->getMultipleSeparator();
        $parts     = $separator !== null ? explode($separator, (string) $cell) : [(string) $cell];

        $values = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '') {
                $values[$this->normalize($part)] = $part;
            }
        }

        return array_values($values);
    }

    /**
     * The cache key of a value: trimmed, and lower-cased unless matching is
     * case-sensitive.
     */
    private function normalize(string $value): string
    {
        $value = trim($value);

        return $this->column->isCaseSensitive() ? $value : mb_strtolower($value);
    }

    private function isValidKey(string $value): bool
    {
        return $this->related->getKeyType() !== 'int' || ctype_digit($value);
    }
}
