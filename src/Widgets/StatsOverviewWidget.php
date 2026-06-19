<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

use Happones\Kinetix\Widgets\Stats\Stat;

class StatsOverviewWidget extends Widget
{
    protected string $type = 'stats';

    /**
     * @var array<int, Stat>
     */
    protected array $stats = [];

    /**
     * Set the stats list.
     *
     * @param array<int, Stat> $stats
     */
    public function stats(array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    protected function getData(): array
    {
        return [
            'stats' => array_map(fn ($stat) => $stat->toArray(), $this->stats),
        ];
    }
}
