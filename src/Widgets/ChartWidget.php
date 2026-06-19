<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

class ChartWidget extends Widget
{
    protected string $type = 'chart';

    protected string $chartType = 'line';

    protected array $labels = [];

    protected array $datasets = [];

    protected array $options = [];

    public function chartType(string $chartType): static
    {
        $this->chartType = $chartType;

        return $this;
    }

    public function labels(array $labels): static
    {
        $this->labels = $labels;

        return $this;
    }

    public function datasets(array $datasets): static
    {
        $this->datasets = $datasets;

        return $this;
    }

    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    protected function getData(): array
    {
        return [
            'chartType' => $this->chartType,
            'labels'    => $this->labels,
            'datasets'  => $this->datasets,
            'options'   => $this->options,
        ];
    }
}
