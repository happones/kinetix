<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

/**
 * A goal / quota progress panel — a value against a target shown as a horizontal
 * bar or a circular ring with the percentage (e.g. "Monthly goal · 72%",
 * "Storage · 64 GB of 100 GB").
 *
 *     ProgressWidget::make()
 *         ->title('Monthly goal')
 *         ->value(7200)->target(10000)
 *         ->display('$7,200')->caption('of $10,000')
 *         ->color('success')->ring();
 */
class ProgressWidget extends Widget
{
    protected string $type = 'progress';

    protected float $value = 0.0;

    protected float $target = 100.0;

    protected ?string $display = null;

    protected ?string $caption = null;

    protected string $color = 'primary';

    protected bool $ring = false;

    public function value(float $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function target(float $target): static
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Override the big value text (defaults to the computed percentage).
     */
    public function display(string $display): static
    {
        $this->display = $display;

        return $this;
    }

    public function caption(string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }

    /**
     * Bar/ring fill color: primary | success | danger | warning | info | gray.
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Render a circular ring instead of a horizontal bar.
     */
    public function ring(bool $ring = true): static
    {
        $this->ring = $ring;

        return $this;
    }

    protected function getData(): array
    {
        $percent = $this->target > 0
            ? (int) round(min(100, max(0, ($this->value / $this->target) * 100)))
            : 0;

        return [
            'value'   => $this->value,
            'target'  => $this->target,
            'percent' => $percent,
            'display' => $this->display ?? "{$percent}%",
            'caption' => $this->caption,
            'color'   => $this->color,
            'ring'    => $this->ring,
        ];
    }
}
