<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class WidgetsGrid implements Arrayable, JsonSerializable
{
    /**
     * @var array<int, Widget>
     */
    protected array $widgets = [];

    protected int|array $columns = 12;

    public static function make(): static
    {
        return new static;
    }

    public function widgets(array $widgets): static
    {
        $this->widgets = $widgets;

        return $this;
    }

    public function columns(int|array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    public function toArray(): array
    {
        // Widgets failing visible()/hidden()/authorize() are dropped before
        // toArray() (and therefore getData()) ever runs — an unauthorized
        // user never receives the widget's payload, not even hidden in the
        // response, and its (possibly costly) data query never executes.
        $widgets = array_values(array_filter(
            $this->widgets,
            static fn (Widget $widget): bool => $widget->shouldRender(),
        ));

        usort($widgets, static fn (Widget $a, Widget $b): int => $a->getSort() <=> $b->getSort());

        return [
            'columns' => $this->columns,
            'widgets' => array_map(static fn (Widget $w): array => $w->toArray(), $widgets),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
