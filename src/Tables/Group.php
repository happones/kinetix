<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use BackedEnum;
use Closure;
use Happones\Kinetix\Data\GroupData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stringable;
use UnitEnum;

/**
 * A row-grouping definition for a {@see Table}, mirroring Filament's
 * `->groups([...])` / `->defaultGroup(...)`.
 *
 * A group buckets the CURRENT dataset's rows by one column — an attribute, or a
 * dot-notation path into an already eager-loaded relation (`author.name`). The
 * table reads the value off each already-fetched record (no per-row query), so
 * grouping never reintroduces the N+1 the table works to avoid.
 *
 * The column name doubles as the group's stable identity (the key the frontend
 * matches `TableRowData::$groupKey` against and the client remembers collapsed
 * state under), so a group is uniquely identified by `getColumn()`.
 */
class Group
{
    protected string $column;

    protected ?string $label = null;

    protected bool $isCollapsible = false;

    /**
     * Resolves the human-readable TITLE shown in a group's header for a given
     * record. Null falls back to the raw grouped value (see {@see date()} for a
     * common override). Signature: `fn (Model $record, mixed $value): ?string`.
     *
     * @var Closure(Model, mixed): (string|null)|null
     */
    protected ?Closure $getTitleFromRecord = null;

    /**
     * Resolves the stable KEY a record is bucketed under. Null falls back to the
     * raw grouped value cast to a string. A custom resolver keeps rows together
     * even when their displayed title is derived (e.g. a date truncated to its
     * day). Signature: `fn (Model $record, mixed $value): (string|int|null)`.
     *
     * @var Closure(Model, mixed): (string|int|null)|null
     */
    protected ?Closure $getKeyFromRecord = null;

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    public static function make(string $column): static
    {
        return new static($column);
    }

    /**
     * Human-readable label for the group as a whole (used by the frontend's
     * group picker). Defaults to a headline of the column name.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Allow the frontend to collapse/expand this group's rows under its header.
     */
    public function collapsible(bool $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    /**
     * Override the header TITLE derived for each record. The value already read
     * off the record is passed as the second argument, so the common case needs
     * no record access:
     *
     *     Group::make('status')->getTitleFromRecord(fn ($record, $value) => ucfirst((string) $value));
     *
     * @param Closure(Model, mixed): (string|null) $callback
     */
    public function getTitleFromRecord(Closure $callback): static
    {
        $this->getTitleFromRecord = $callback;

        return $this;
    }

    /**
     * Override the stable KEY rows are bucketed under. Pair this with
     * {@see getTitleFromRecord()} when the display title is not 1:1 with the key.
     *
     * @param Closure(Model, mixed): (string|int|null) $callback
     */
    public function getKeyFromRecord(Closure $callback): static
    {
        $this->getKeyFromRecord = $callback;

        return $this;
    }

    /**
     * Group by the DATE portion of a datetime column: rows fall into one bucket
     * per calendar day, keyed `Y-m-d` and titled with the same date. A shortcut
     * for the common "group by created_at (day)" case.
     */
    public function date(string $format = 'Y-m-d'): static
    {
        $toDate = static function (mixed $value) use ($format): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            try {
                return Carbon::parse((string) $value)->format($format);
            } catch (\Throwable) {
                return (string) $value;
            }
        };

        $this->getKeyFromRecord   = static fn (Model $record, mixed $value): ?string => $toDate($value);
        $this->getTitleFromRecord = static fn (Model $record, mixed $value): ?string => $toDate($value);

        return $this;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getLabel(): string
    {
        return $this->label ?? (string) str(str_replace('.', ' ', $this->column))->headline();
    }

    public function isCollapsible(): bool
    {
        return $this->isCollapsible;
    }

    /**
     * Resolve the stable bucket key for a record. Reads the value already loaded
     * on the record via dot-notation (`data_get`) — NEVER a fresh query — then
     * applies any custom key resolver. Null when the record has no value.
     */
    public function resolveKey(Model $record): string|int|null
    {
        $value = data_get($record, $this->column);

        if ($this->getKeyFromRecord !== null) {
            return ($this->getKeyFromRecord)($record, $value);
        }

        return self::keyOf($value);
    }

    /**
     * Resolve the header title for a record (what the user reads above the
     * bucket). Falls back to the raw value when no title resolver is set.
     */
    public function resolveTitle(Model $record): ?string
    {
        $value = data_get($record, $this->column);

        if ($this->getTitleFromRecord !== null) {
            return ($this->getTitleFromRecord)($record, $value);
        }

        return self::titleOf($value);
    }

    /**
     * A bucket key for any value a column casts to. An enum used to be cast to
     * string — a 500 — and `false` became `''`, which read as "no value".
     */
    private static function keyOf(mixed $value): ?string
    {
        return match (true) {
            $value === null                                 => null,
            $value instanceof BackedEnum                    => (string) $value->value,
            $value instanceof UnitEnum                      => $value->name,
            is_bool($value)                                 => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default                                         => null,
        };
    }

    /**
     * A header title: an enum's own label when it has one (`getLabel()`), a
     * boolean as yes/no.
     */
    private static function titleOf(mixed $value): ?string
    {
        if ($value instanceof UnitEnum && method_exists($value, 'getLabel')) {
            $label = $value->getLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        if (is_bool($value)) {
            return (string) __($value ? 'kinetix.table_group_true' : 'kinetix.table_group_false');
        }

        return self::keyOf($value);
    }

    public function toData(): GroupData
    {
        return new GroupData(
            column: $this->column,
            label: $this->getLabel(),
            collapsible: $this->isCollapsible,
        );
    }
}
