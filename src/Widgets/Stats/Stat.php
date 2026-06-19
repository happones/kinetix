<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets\Stats;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Stat implements Arrayable, JsonSerializable
{
    protected string $label;

    protected mixed $value;

    protected ?string $description = null;

    protected ?string $descriptionIcon = null;

    protected ?string $descriptionColor = 'gray'; // success, danger, warning, info, gray

    protected array $chart = [];

    public function __construct(string $label, mixed $value)
    {
        $this->label = $label;
        $this->value = $value;
    }

    public static function make(string $label, mixed $value): static
    {
        return new static($label, $value);
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function descriptionIcon(string $icon): static
    {
        $this->descriptionIcon = $icon;

        return $this;
    }

    public function descriptionColor(string $color): static
    {
        $this->descriptionColor = $color;

        return $this;
    }

    public function chart(array $chart): static
    {
        $this->chart = $chart;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'label'            => $this->label,
            'value'            => $this->value,
            'description'      => $this->description,
            'descriptionIcon'  => $this->descriptionIcon,
            'descriptionColor' => $this->descriptionColor,
            'chart'            => $this->chart,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
