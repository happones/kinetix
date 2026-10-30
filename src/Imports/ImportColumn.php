<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Closure;
use Happones\Kinetix\Data\ImportColumnData;
use Happones\Kinetix\Support\AutoLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImportColumn
{
    protected string $name;

    protected mixed $label;

    /**
     * Translation key the label is read from, in the current locale — and in
     * every configured locale when matching an uploaded header.
     */
    protected ?string $labelKey = null;

    protected bool $isRequiredMapping = false;

    /**
     * Whether the cell holds a secret (password, PIN): it is blanked in the
     * failed-rows file a user downloads.
     */
    protected bool $isSensitive = false;

    /**
     * @var array<int, mixed>
     */
    protected array $rules = [];

    /**
     * Alternative header names that should auto-map to this column.
     *
     * @var array<int, string>
     */
    protected array $guesses = [];

    protected ?Closure $fillRecordUsing = null;

    protected ?Closure $castStateUsing = null;

    /**
     * The owning model's relation this column fills (BelongsTo or
     * BelongsToMany), null for a plain attribute.
     */
    protected ?string $relationship = null;

    /**
     * The related columns a cell is looked up by, in order — the first that
     * matches wins.
     *
     * @var array<int, string>
     */
    protected array $relationshipMatchBy = [];

    protected ?Closure $relationshipQuery = null;

    protected Closure|bool $createMissing = false;

    protected ?string $multipleSeparator = null;

    protected bool $caseSensitive = false;

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->label = AutoLabel::from($name);
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->label    = $label;
        $this->labelKey = null;

        return $this;
    }

    /**
     * Label the column with a translation key instead of a fixed string: the
     * template is written in the downloading user's language, and a header in
     * ANY configured locale (`kinetix.locale.locales`) maps back to it — so a
     * template downloaded in English still maps when uploaded in Spanish.
     */
    public function labelKey(string $key): static
    {
        $this->labelKey = $key;
        $this->label    = $key;

        return $this;
    }

    /**
     * Mark this column as requiring a mapped source column before import can start.
     */
    public function requiredMapping(bool $condition = true): static
    {
        $this->isRequiredMapping = $condition;

        return $this;
    }

    /**
     * Validation rules for the cell, in any shape Validator::make() accepts:
     * a pipe-delimited string (`'required|string'`), a list, rule objects or
     * closures. A string is split on `|` the way Laravel splits it.
     *
     * @param array<int, mixed>|string|object $rules
     */
    public function rules(array|string|object $rules): static
    {
        $rules = match (true) {
            is_string($rules) => explode('|', $rules),
            is_array($rules)  => $rules,
            default           => [$rules],
        };

        foreach ($rules as $rule) {
            // A list may still carry a piped string: ['required|string', 'max:5'].
            array_push($this->rules, ...(is_string($rule) ? explode('|', $rule) : [$rule]));
        }

        return $this;
    }

    /**
     * Additional header names that auto-map to this column.
     *
     * @param array<int, string> $aliases
     */
    public function aliases(array $aliases): static
    {
        $this->guesses = array_merge($this->guesses, $aliases);

        return $this;
    }

    /**
     * Same as {@see aliases()}.
     *
     * @param array<int, string> $guesses
     */
    public function guess(array $guesses): static
    {
        return $this->aliases($guesses);
    }

    /**
     * Blank this column's cell in the failed-rows file, so a password or PIN
     * a user typed into the spreadsheet isn't handed back in a download.
     */
    public function sensitive(bool $condition = true): static
    {
        $this->isSensitive = $condition;

        return $this;
    }

    /**
     * Customize how the parsed value is written onto the record.
     */
    public function fillRecordUsing(Closure $callback): static
    {
        $this->fillRecordUsing = $callback;

        return $this;
    }

    /**
     * Transform the raw string value before it is stored.
     */
    public function castStateUsing(Closure $callback): static
    {
        $this->castStateUsing = $callback;

        return $this;
    }

    /**
     * Fill a relation from the cell: the related record is looked up by
     * each of $matchBy in turn, so a file may hold the id OR the name.
     *
     *     ImportColumn::make('category')->relationship('category', ['id', 'name']);
     *     ImportColumn::make('tags')->relationship('tags', ['name'])->multiple(',');
     *
     * A BelongsTo sets the foreign key; a BelongsToMany syncs the related
     * keys once the record is saved. Lookups are batched per chunk of rows
     * and cached for the import, so a file costs a handful of queries however
     * many rows reference the same records. A cell that matches nothing — or
     * more than one record — fails its row.
     *
     * @param array<int, string>|string $matchBy related columns, in order
     *                                           (default: the related key)
     */
    public function relationship(string $name, array|string $matchBy = []): static
    {
        $this->relationship        = $name;
        $this->relationshipMatchBy = array_values((array) $matchBy);

        return $this;
    }

    /**
     * Bound the records a cell may match — REQUIRED for a tenant-owned
     * relation, since the worker has no request:
     *
     *     ->relationshipQuery(fn (Builder $query, array $context) => $query->where('team_id', $context['team_id']))
     *
     * @param Closure(Builder<Model>, array<string, mixed>): mixed $callback
     */
    public function relationshipQuery(Closure $callback): static
    {
        $this->relationshipQuery = $callback;

        return $this;
    }

    /**
     * Create the related record a cell names when none matches (off by
     * default: the row fails instead). Pass a closure to set its attributes —
     * it receives the cell and the import's context:
     *
     *     ->createMissing(fn (string $name, array $context) => ['name' => $name, 'team_id' => $context['team_id']])
     *
     * true creates it with the first non-key $matchBy column set to the cell.
     *
     * @param bool|Closure(string, array<string, mixed>): array<string, mixed> $condition
     */
    public function createMissing(bool|Closure $condition = true): static
    {
        $this->createMissing = $condition;

        return $this;
    }

    /**
     * One cell lists several related records (`Ofertas, Nuevos`) — for a
     * BelongsToMany.
     */
    public function multiple(string $separator = ','): static
    {
        $this->multipleSeparator = $separator === '' ? ',' : $separator;

        return $this;
    }

    /**
     * Match text exactly as cased. Off by default ("bebidas" finds
     * "Bebidas"); turn it on so a large related table's index on the column
     * is used.
     */
    public function caseSensitive(bool $condition = true): static
    {
        $this->caseSensitive = $condition;

        return $this;
    }

    public function getRelationship(): ?string
    {
        return $this->relationship;
    }

    public function isRelationship(): bool
    {
        return $this->relationship !== null;
    }

    /**
     * @return array<int, string>
     */
    public function getRelationshipMatchBy(): array
    {
        return $this->relationshipMatchBy;
    }

    public function getRelationshipQuery(): ?Closure
    {
        return $this->relationshipQuery;
    }

    public function getCreateMissing(): Closure|bool
    {
        return $this->createMissing;
    }

    public function getMultipleSeparator(): ?string
    {
        return $this->multipleSeparator;
    }

    public function isCaseSensitive(): bool
    {
        return $this->caseSensitive;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->labelKey !== null ? (string) __($this->labelKey) : (string) $this->label;
    }

    public function isSensitive(): bool
    {
        return $this->isSensitive;
    }

    public function isRequiredMapping(): bool
    {
        return $this->isRequiredMapping;
    }

    /**
     * @return array<int, mixed>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * The candidate names this column should auto-match against, normalized.
     *
     * @return array<int, string>
     */
    public function getGuesses(): array
    {
        return $this->guesses;
    }

    /**
     * Normalize a header/name for tolerant comparison: case, spacing,
     * punctuation and accents don't count ("Teléfono" = "telefono").
     */
    public static function normalize(string $value): string
    {
        return (string) str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '');
    }

    /**
     * Determine whether a file header should auto-map to this column.
     */
    public function matchesHeader(string $header): bool
    {
        $normalized = static::normalize($header);

        if ($normalized === '') {
            return false;
        }

        $candidates = array_merge([$this->name, $this->getLabel()], $this->labelTranslations(), $this->guesses);

        foreach ($candidates as $candidate) {
            if (static::normalize((string) $candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label in every configured locale, when it comes from a key.
     *
     * @return array<int, string>
     */
    protected function labelTranslations(): array
    {
        if ($this->labelKey === null) {
            return [];
        }

        $locales = array_keys((array) config('kinetix.locale.locales', []));

        return array_map(
            fn (string $locale): string => (string) __($this->labelKey, [], $locale),
            array_unique([...$locales, (string) config('app.fallback_locale', 'en')]),
        );
    }

    /**
     * Cast the raw parsed value before persisting.
     */
    public function castState(mixed $value): mixed
    {
        if ($this->castStateUsing !== null) {
            return ($this->castStateUsing)($value);
        }

        return $value;
    }

    /**
     * Write the value onto the record. Returns true when handled by a custom closure.
     *
     * @param array<string, mixed> $row
     */
    public function fillRecord(Model $record, mixed $value, array $row): bool
    {
        if ($this->fillRecordUsing !== null) {
            ($this->fillRecordUsing)($record, $value, $row);

            return true;
        }

        return false;
    }

    public function toData(): ImportColumnData
    {
        return new ImportColumnData(
            name: $this->name,
            label: $this->getLabel(),
            isRequired: $this->isRequiredMapping,
            guesses: $this->guesses,
        );
    }
}
