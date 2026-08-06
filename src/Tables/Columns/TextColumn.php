<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Support\Concerns\FormatsDates;
use Happones\Kinetix\Support\Concerns\FormatsMoney;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

class TextColumn extends Column
{
    use FormatsDates;
    use FormatsMoney;

    protected bool $isBadge = false;

    protected bool $isConfidential = false;

    protected string|Closure|null $badgeColor = 'gray';

    protected ?int $limit = null;

    protected string|Closure|null $description = null;

    protected string $descriptionPosition = 'below'; // below, above

    protected ?string $separator = null;

    protected bool $isHtml = false;

    protected bool $wrap = false;

    /** @var array{decimals: int, locale: string|null}|null */
    protected ?array $numericConfig = null;

    protected string|Closure|null $url = null;

    protected bool $openUrlInNewTab = false;

    protected function getType(): string
    {
        return 'text';
    }

    public function badge(bool $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    /**
     * UI-only affordance flag (a padlock icon) — the actual masking is
     * always enforced by `ConfidentialCast` on the model attribute itself,
     * regardless of whether a column declares this.
     */
    public function confidential(bool $condition = true): static
    {
        $this->isConfidential = $condition;

        return $this;
    }

    public function badgeColor(string|Closure $color): static
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function description(string|Closure $description, string $position = 'below'): static
    {
        $this->description         = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    /**
     * Glue for ARRAY states (a TagsInput/CheckboxList/multi-Select attribute,
     * a JSON cast): plain text implodes with it. A `->badge()` column ignores
     * the glue and renders one pill PER item instead.
     */
    public function separator(string $separator = ', '): static
    {
        $this->separator = $separator;

        return $this;
    }

    /**
     * Render the value as HTML (e.g. a RichEditor attribute). The value is
     * trusted as-is — sanitize user-generated content server-side. Combined
     * with `limit()`, tags are stripped BEFORE truncating so the cut can
     * never break markup mid-tag.
     */
    public function html(bool $condition = true): static
    {
        $this->isHtml = $condition;

        return $this;
    }

    /**
     * Localized decimal formatting via intl (mirrors `money()` — resolves the
     * argument locale, then `->locale()`, then the app locale).
     */
    public function numeric(int $decimals = 0, ?string $locale = null): static
    {
        $this->numericConfig = ['decimals' => $decimals, 'locale' => $locale];

        return $this;
    }

    /**
     * Turn the cell value into a link (per record via a Closure). Unlike
     * `Table::recordUrl()` — the whole ROW — this links just this column.
     */
    public function url(string|Closure $url, bool $openUrlInNewTab = false): static
    {
        $this->url             = $url;
        $this->openUrlInNewTab = $openUrlInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool $condition = true): static
    {
        $this->openUrlInNewTab = $condition;

        return $this;
    }

    /**
     * Let long values wrap onto multiple lines instead of the table's default
     * single-line nowrap cells.
     */
    public function wrap(bool $condition = true): static
    {
        $this->wrap = $condition;

        return $this;
    }

    public function getUrl(Model $record): ?string
    {
        if ($this->url === null) {
            return null;
        }

        return $this->url instanceof Closure ? ($this->url)($record) : $this->url;
    }

    public function getState(Model $record): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        // Auto-resolve Enums to their labels
        if ($value instanceof HasLabel || (is_object($value) && method_exists($value, 'getLabel'))) {
            $value = $value->getLabel();
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        // Apply date formatting (localized isoFormat or plain PHP format).
        $value = $this->formatDateValue($value);

        // Apply money formatting (localized intl currency).
        $value = $this->formatMoneyValue($value);

        // Localized decimal formatting (numeric()).
        if ($this->numericConfig !== null && is_numeric($value)) {
            $value = $this->formatNumericValue($value);
        }

        // Array states (TagsInput, CheckboxList, multi-Select, JSON casts):
        // badges render one pill per item; plain text implodes — otherwise
        // Vue would JSON-stringify the array into the cell.
        if (is_array($value)) {
            $items = array_map(
                static fn (mixed $item): string => is_scalar($item) || $item instanceof \Stringable
                    ? (string) $item
                    : (string) json_encode($item),
                $value,
            );

            return $this->isBadge
                ? array_values($items)
                : implode($this->separator ?? ', ', $items);
        }

        // Apply string limits. HTML strips tags FIRST so the cut can never
        // break markup mid-tag (a truncated value downgrades to plain text).
        if ($this->limit !== null && is_string($value)) {
            if ($this->isHtml) {
                $value = strip_tags($value);
            }

            $value = str($value)->limit($this->limit)->toString();
        }

        return $value;
    }

    /**
     * Localized decimal formatting via intl NumberFormatter.
     */
    protected function formatNumericValue(mixed $value): string
    {
        $config = $this->numericConfig ?? ['decimals' => 0, 'locale' => null];
        $locale = $config['locale']    ?? $this->dateLocale ?? app()->getLocale();

        if (! class_exists(\NumberFormatter::class)) {
            return number_format((float) $value, $config['decimals']);
        }

        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $config['decimals']);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $config['decimals']);

        return (string) $formatter->format((float) $value);
    }

    /**
     * Whether this column renders badges (serialization + hosts read this
     * instead of round-tripping toArray()).
     */
    public function isBadgeColumn(): bool
    {
        return $this->isBadge;
    }

    public function getDescriptionPosition(): string
    {
        return $this->descriptionPosition;
    }

    public function isWrapped(): bool
    {
        return $this->wrap;
    }

    /**
     * Get the description text computed for a given record.
     */
    public function getDescription(Model $record): ?string
    {
        if ($this->description === null) {
            return null;
        }

        if ($this->description instanceof Closure) {
            return ($this->description)($record);
        }

        return $this->description;
    }

    /**
     * Get the badge color resolved for a given record.
     */
    public function getBadgeColor(Model $record): string
    {
        $rawState = parent::getState($record);

        if ($rawState instanceof HasColor || (is_object($rawState) && method_exists($rawState, 'getColor'))) {
            return $rawState->getColor() ?? 'gray';
        }

        if ($this->badgeColor instanceof Closure) {
            return ($this->badgeColor)($this->getState($record), $record);
        }

        return $this->badgeColor ?? 'gray';
    }

    protected function getExtraData(): array
    {
        return [
            'isBadge'             => $this->isBadge,
            'descriptionPosition' => $this->descriptionPosition,
            'isConfidential'      => $this->isConfidential,
            'isHtml'              => $this->isHtml ?: null,
            'wrap'                => $this->wrap ?: null,
            'openUrlInNewTab'     => $this->openUrlInNewTab ?: null,
        ];
    }
}
