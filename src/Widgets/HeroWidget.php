<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

/**
 * A prominent hero / call-to-action card — a greeting + a headline value with a
 * delta and a primary button (e.g. "Congratulations Toby! · $15,231.89 · +65%
 * from last month · View Sales").
 *
 *     HeroWidget::make()
 *         ->title('Congratulations Toby! 🎉')
 *         ->subtitle('Best seller of the month')
 *         ->value('$15,231.89')
 *         ->delta('+65% from last month', 'success')
 *         ->action('View Sales', '/sales')
 *         ->gradient();
 */
class HeroWidget extends Widget
{
    protected string $type = 'hero';

    protected ?string $subtitle = null;

    protected ?string $value = null;

    protected ?string $delta = null;

    protected ?string $deltaColor = 'success';

    protected ?string $actionLabel = null;

    protected ?string $actionUrl = null;

    protected bool $gradient = false;

    public function subtitle(string $subtitle): static
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function value(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * A change/trend line under the value (e.g. "+65% from last month").
     */
    public function delta(string $delta, string $color = 'success'): static
    {
        $this->delta      = $delta;
        $this->deltaColor = $color;

        return $this;
    }

    public function action(string $label, string $url): static
    {
        $this->actionLabel = $label;
        $this->actionUrl   = $url;

        return $this;
    }

    /**
     * Render a subtle accent gradient background.
     */
    public function gradient(bool $gradient = true): static
    {
        $this->gradient = $gradient;

        return $this;
    }

    protected function getData(): array
    {
        return [
            'subtitle'    => $this->subtitle,
            'value'       => $this->value,
            'delta'       => $this->delta,
            'deltaColor'  => $this->deltaColor,
            'actionLabel' => $this->actionLabel,
            'actionUrl'   => $this->actionUrl,
            'gradient'    => $this->gradient,
        ];
    }
}
