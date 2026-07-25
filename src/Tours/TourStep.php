<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

/**
 * One product-tour step: the element to spotlight (CSS selector) and its
 * popover content. `side`/`align` map straight to driver.js's positioning.
 *
 *     TourStep::make('[data-tour=create]')
 *         ->title(__('tours.posts.create_title'))
 *         ->description(__('tours.posts.create_body'))
 *         ->side('bottom');
 */
class TourStep
{
    protected ?string $title = null;

    protected ?string $description = null;

    protected ?string $side = null;

    protected ?string $align = null;

    final protected function __construct(public readonly string $selector) {}

    public static function make(string $selector): static
    {
        return new static($selector);
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Preferred popover side: 'top' | 'right' | 'bottom' | 'left'.
     */
    public function side(string $side): static
    {
        $this->side = $side;

        return $this;
    }

    /**
     * Popover alignment on that side: 'start' | 'center' | 'end'.
     */
    public function align(string $align): static
    {
        $this->align = $align;

        return $this;
    }

    /**
     * @return array{selector: string, title: ?string, description: ?string, side: ?string, align: ?string}
     */
    public function toArray(): array
    {
        return [
            'selector'    => $this->selector,
            'title'       => $this->title,
            'description' => $this->description,
            'side'        => $this->side,
            'align'       => $this->align,
        ];
    }
}
