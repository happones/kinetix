<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class SelectFilter extends Filter
{
    /**
     * @var array<string, string>|Closure
     */
    protected array|Closure $options = [];

    protected ?string $attribute = null;

    protected function getType(): string
    {
        return 'select';
    }

    /**
     * Set the dropdown options.
     *
     * @param array<string, string>|Closure $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the database column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Get the options array.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        if ($this->options instanceof Closure) {
            return ($this->options)();
        }

        return $this->options;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            parent::apply($query, $value);

            return;
        }

        $attribute = $this->attribute ?? $this->name;
        $query->where($attribute, $value);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'options' => $this->getOptions(),
        ]);
    }
}
