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
        // Sort widgets by sort order ascending
        usort($this->widgets, function ($a, $b) {
            $sortA = $a->toArray()['sort'] ?? 0;
            $sortB = $b->toArray()['sort'] ?? 0;

            return $sortA <=> $sortB;
        });

        return [
            'columns' => $this->columns,
            'widgets' => array_map(fn ($w) => $w->toArray(), $this->widgets),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
