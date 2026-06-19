<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class Filter
{
    protected string $name;

    protected string $label;

    protected ?Closure $query = null;

    protected mixed $default = null;

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->label = (string) str($name)->headline();
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

    public function query(Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);
        }
    }

    /**
     * Convert the filter definition to array for frontend rendering.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'    => $this->name,
            'label'   => $this->label,
            'default' => $this->default,
            'type'    => $this->getType(),
        ];
    }

    protected function getType(): string
    {
        return 'checkbox';
    }
}
