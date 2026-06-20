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
     * @param array<string, string>|Closure|string $options
     */
    public function options(array|Closure|string $options): static
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

        if (is_string($this->options) && is_subclass_of($this->options, \UnitEnum::class)) {
            $options = [];
            foreach ($this->options::cases() as $case) {
                $label = $case instanceof \Happones\Kinetix\Support\Contracts\HasLabel
                    ? $case->getLabel()
                    : ($case instanceof \BackedEnum ? $case->value : $case->name);

                $value = $case instanceof \BackedEnum ? $case->value : $case->name;
                $options[(string) $value] = $label;
            }

            return $options;
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

    protected function getExtraData(): array
    {
        return [
            'options' => $this->getOptions(),
        ];
    }
}
