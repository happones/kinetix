<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

/**
 * A ratings summary widget: an average score with stars and a per-level
 * breakdown of how many reviews fall on each star (like a product's "Customer
 * Reviews" panel).
 *
 *     RatingWidget::make()
 *         ->title('Customer reviews')
 *         ->average(4.5)
 *         ->total(5500)
 *         ->breakdown([5 => 4000, 4 => 2100, 3 => 800, 2 => 631, 1 => 344]);
 */
class RatingWidget extends Widget
{
    protected string $type = 'rating';

    protected float $average = 0.0;

    protected int $total = 0;

    protected int $max = 5;

    /**
     * Star level (1..max) => number of reviews.
     *
     * @var array<int, int>
     */
    protected array $breakdown = [];

    public function average(float $average): static
    {
        $this->average = $average;

        return $this;
    }

    public function total(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    /**
     * @param array<int, int> $breakdown level => count
     */
    public function breakdown(array $breakdown): static
    {
        $this->breakdown = $breakdown;

        return $this;
    }

    protected function getData(): array
    {
        $counts   = $this->breakdown;
        $maxCount = $counts === [] ? 0 : max($counts);

        $levels = [];
        for ($level = $this->max; $level >= 1; $level--) {
            $count = (int) ($counts[$level] ?? 0);

            $levels[] = [
                'level' => $level,
                'count' => $count,
                'pct'   => $maxCount > 0 ? (int) round(($count / $maxCount) * 100) : 0,
            ];
        }

        return [
            'average'   => $this->average,
            'total'     => $this->total,
            'max'       => $this->max,
            'breakdown' => $levels,
        ];
    }
}
