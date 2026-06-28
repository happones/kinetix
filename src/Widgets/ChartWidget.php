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

    protected bool $stacked = false;

    protected bool $legend = false;

    protected ?string $centerLabel = null;

    protected ?string $centerValue = null;

    /**
     * Headline metrics shown in the chart header (e.g. DESKTOP / MOBILE totals).
     *
     * @var array<int, array{label: string, value: string, badge: string|null, badgeColor: string|null}>
     */
    protected array $metrics = [];

    /**
     * line | area | bar | horizontalBar | pie | doughnut.
     */
    public function chartType(string $chartType): static
    {
        $this->chartType = $chartType;

        return $this;
    }

    /**
     * Stack multiple series (area / bar charts).
     */
    public function stacked(bool $stacked = true): static
    {
        $this->stacked = $stacked;

        return $this;
    }

    /**
     * Show a legend of the dataset labels below the chart.
     */
    public function legend(bool $legend = true): static
    {
        $this->legend = $legend;

        return $this;
    }

    /**
     * Center label for donut charts (e.g. a total): a big value + caption.
     */
    public function centerLabel(string $value, ?string $caption = null): static
    {
        $this->centerValue = $value;
        $this->centerLabel = $caption;

        return $this;
    }

    /**
     * Add a headline metric to the chart header (e.g. `metric('Desktop', '24,828')`).
     * Chain for several; an optional badge shows a trend chip beside the value.
     */
    public function metric(string $label, string $value, ?string $badge = null, ?string $badgeColor = 'gray'): static
    {
        $this->metrics[] = [
            'label'      => $label,
            'value'      => $value,
            'badge'      => $badge,
            'badgeColor' => $badge !== null ? $badgeColor : null,
        ];

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
            'chartType'   => $this->chartType,
            'labels'      => $this->labels,
            'datasets'    => $this->datasets,
            'options'     => $this->options,
            'stacked'     => $this->stacked,
            'legend'      => $this->legend,
            'centerValue' => $this->centerValue,
            'centerLabel' => $this->centerLabel,
            'metrics'     => $this->metrics,
        ];
    }
}
