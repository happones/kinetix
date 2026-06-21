<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Closure;
use Happones\Kinetix\Data\ImportColumnData;
use Illuminate\Database\Eloquent\Model;

class ImportColumn
{
    protected string $name;

    protected mixed $label;

    protected bool $isRequiredMapping = false;

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

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->label = (string) str(str_replace('.', ' ', $name))->headline();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->label = $label;

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
     * @param array<int, mixed>|string $rules
     */
    public function rules(array|string $rules): static
    {
        $this->rules = array_merge($this->rules, is_array($rules) ? $rules : [$rules]);

        return $this;
    }

    /**
     * Additional header names/aliases used for automatic mapping.
     *
     * @param array<int, string> $guesses
     */
    public function guess(array $guesses): static
    {
        $this->guesses = array_merge($this->guesses, $guesses);

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

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return (string) $this->label;
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
     * Normalize a header/name for tolerant comparison (case/spacing/punctuation insensitive).
     */
    public static function normalize(string $value): string
    {
        return (string) str($value)->lower()->replaceMatches('/[^a-z0-9]+/i', '');
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

        $candidates = array_merge([$this->name, (string) $this->label], $this->guesses);

        foreach ($candidates as $candidate) {
            if (static::normalize((string) $candidate) === $normalized) {
                return true;
            }
        }

        return false;
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
            label: (string) $this->label,
            isRequired: $this->isRequiredMapping,
            guesses: $this->guesses,
        );
    }
}
