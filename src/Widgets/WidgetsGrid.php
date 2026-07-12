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

    /**
     * @var int|string|array<string, int|string>
     */
    protected mixed $gap = '1.5rem';

    protected string $layout = 'grid';

    protected bool $dense = false;

    /**
     * @var int|array<string, int>
     */
    protected int|array $masonryColumns = 3;

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

    /**
     * Gap between widgets. A bare number/string applies to every breakpoint;
     * an array (`default`/`sm`/`md`/`lg`/`xl`/`2xl`) varies it responsively —
     * same shape as `columns()`. Accepts any CSS length (`'1rem'`, `16`→`16px`).
     *
     * @param int|string|array<string, int|string> $gap
     */
    public function gap(mixed $gap): static
    {
        $this->gap = $gap;

        return $this;
    }

    /**
     * Backfill gaps left by uneven widget heights within the standard
     * `columnSpan`-based grid (`grid-auto-flow: dense`) — later, smaller
     * widgets are pulled up into earlier holes instead of strictly
     * following DOM order. Visual order may then differ from DOM/reading
     * order, so it's opt-in.
     */
    public function dense(bool $dense = true): static
    {
        $this->dense = $dense;

        return $this;
    }

    /**
     * Switch to a true column-balanced masonry layout: each widget occupies
     * exactly one column (its `columnSpan` is ignored) and is placed into
     * whichever column is currently shortest, eliminating height gaps
     * entirely. Best for widgets of similar width but varying height (stat
     * cards, lists) — for a layout that still needs multi-column-span
     * widgets (e.g. a wide chart beside two narrow stats), use `dense()`
     * instead, which keeps `columnSpan` semantics.
     *
     * `$columns` is the number of masonry columns — a *different* number
     * than the 12-unit virtual grid `columns()` configures for `layout:
     * 'grid'` — and accepts the same responsive shape (`default`/`sm`/`md`/
     * `lg`/`xl`/`2xl`). Defaults to 3, matching a typical dashboard.
     *
     * @param int|array<string, int> $columns
     */
    public function masonry(int|array $columns = 3): static
    {
        $this->layout         = 'masonry';
        $this->masonryColumns = $columns;

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
            'columns'        => $this->columns,
            'gap'            => $this->gap,
            'layout'         => $this->layout,
            'dense'          => $this->dense,
            'masonryColumns' => $this->masonryColumns,
            'widgets'        => array_map(static fn (Widget $w): array => $w->toArray(), $widgets),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
