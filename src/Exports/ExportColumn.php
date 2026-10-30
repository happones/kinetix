<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use BackedEnum;
use Closure;
use Happones\Kinetix\Support\AutoLabel;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use UnitEnum;

class ExportColumn
{
    protected string $name;

    protected mixed $label;

    protected ?Closure $formatStateUsing = null;

    protected string $separator = ', ';

    protected bool $distinct = false;

    /**
     * @var array<int, Summarizer>
     */
    protected array $summarizers = [];

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
        $this->label = $label;

        return $this;
    }

    /**
     * Transform the value before it is written to the export cell.
     */
    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    /**
     * What joins the values of a path through a to-many relation
     * (`tags.name` → "Oferta, Nuevo").
     */
    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    /**
     * List each value of a to-many path once (`items.product.category.name`
     * → "Bebidas, Botanas", not "Bebidas, Bebidas, Botanas").
     */
    public function distinct(bool $condition = true): static
    {
        $this->distinct = $condition;

        return $this;
    }

    /**
     * Add summarizer(s) whose value(s) appear in the export's totals row.
     *
     * @param Summarizer|array<int, Summarizer> $summarizers
     */
    public function summarize(mixed $summarizers): static
    {
        $this->summarizers = is_array($summarizers) ? array_values($summarizers) : [$summarizers];

        return $this;
    }

    /**
     * @return array<int, Summarizer>
     */
    public function getSummarizers(): array
    {
        return $this->summarizers;
    }

    public function hasSummarizers(): bool
    {
        return $this->summarizers !== [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHeading(): string
    {
        return (string) $this->label;
    }

    /**
     * Resolve the cell value for a record (dot-notation aware, enum friendly).
     * A path through a to-many relation (`tags.name`, `items.product.sku`)
     * joins every value with {@see separator()}.
     */
    public function getState(Model $record): mixed
    {
        [$value, $isList] = $this->resolvePath($record, explode('.', $this->name));

        if ($isList) {
            $values = array_map(fn (mixed $item): string => (string) $this->present($item), $value);
            $value  = implode($this->separator, $this->distinct ? array_values(array_unique($values)) : $values);
        } else {
            $value = $this->present($value);
        }

        if ($this->formatStateUsing !== null) {
            $value = ($this->formatStateUsing)($value, $record);
        }

        return $value;
    }

    /**
     * Walk the path: through models segment by segment, across every item of
     * a to-many relation, and into a plain or JSON value with data_get().
     *
     * @param  array<int, string>       $segments
     * @return array{0: mixed, 1: bool} the value, and whether it is a list
     */
    private function resolvePath(mixed $target, array $segments): array
    {
        foreach ($segments as $index => $segment) {
            if ($target instanceof Enumerable) {
                $rest = array_slice($segments, $segment === '*' ? $index + 1 : $index);

                $values = [];

                foreach ($target as $item) {
                    [$value, $isList] = $rest === [] ? [$item, false] : $this->resolvePath($item, $rest);

                    foreach ($isList ? $value : [$value] as $one) {
                        if ($one !== null && $one !== '') {
                            $values[] = $one;
                        }
                    }
                }

                return [$values, true];
            }

            if (! $target instanceof Model) {
                return [data_get($target, array_slice($segments, $index)), false];
            }

            $target = data_get($target, $segment);

            if ($target === null) {
                return [null, false];
            }
        }

        return [$target, false];
    }

    private function present(mixed $value): mixed
    {
        if ($value instanceof HasLabel || (is_object($value) && method_exists($value, 'getLabel'))) {
            return $value->getLabel();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }
}
