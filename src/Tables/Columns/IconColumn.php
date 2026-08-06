<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasIcon;
use Illuminate\Database\Eloquent\Model;

class IconColumn extends Column
{
    protected bool $isBoolean = false;

    /**
     * Array mapping of icon => Closure or value.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Array mapping of color => Closure or value.
     *
     * @var array<string, mixed>
     */
    protected array $colors = [];

    protected function getType(): string
    {
        return 'icon';
    }

    protected string $trueIcon = 'check-circle';

    protected string $falseIcon = 'x-circle';

    protected string $trueColor = 'success';

    protected string $falseColor = 'danger';

    protected ?int $size = null;

    public function boolean(): static
    {
        $this->isBoolean = true;

        return $this;
    }

    public function trueIcon(string $icon): static
    {
        $this->trueIcon = $icon;

        return $this;
    }

    public function falseIcon(string $icon): static
    {
        $this->falseIcon = $icon;

        return $this;
    }

    public function trueColor(string $color): static
    {
        $this->trueColor = $color;

        return $this;
    }

    public function falseColor(string $color): static
    {
        $this->falseColor = $color;

        return $this;
    }

    /**
     * Icon size in pixels (defaults to 20 on the frontend).
     */
    public function size(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Map icons to states.
     *
     * @param array<string, mixed> $options
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Map colors to states.
     *
     * @param array<string, mixed> $colors
     */
    public function colors(array $colors): static
    {
        $this->colors = $colors;

        return $this;
    }

    /**
     * Resolve the icon string for the given record.
     */
    public function getIcon(Model $record): ?string
    {
        $state = $this->getState($record);

        if ($state instanceof HasIcon || (is_object($state) && method_exists($state, 'getIcon'))) {
            return $state->getIcon();
        }

        if ($this->isBoolean) {
            return (bool) $state ? $this->trueIcon : $this->falseIcon;
        }

        foreach ($this->options as $icon => $condition) {
            if ($condition instanceof Closure) {
                if ($condition($state, $record)) {
                    return $icon;
                }
            } elseif ($condition === $state) {
                return $icon;
            }
        }

        return null;
    }

    /**
     * Resolve the color class for the icon in the given record.
     */
    public function getIconColor(Model $record): string
    {
        $state = $this->getState($record);

        if ($state instanceof HasColor || (is_object($state) && method_exists($state, 'getColor'))) {
            return $state->getColor() ?? 'gray';
        }

        if ($this->isBoolean) {
            return (bool) $state ? $this->trueColor : $this->falseColor;
        }

        foreach ($this->colors as $color => $condition) {
            if ($condition instanceof Closure) {
                if ($condition($state, $record)) {
                    return $color;
                }
            } elseif ($condition === $state) {
                return $color;
            }
        }

        return 'gray';
    }

    protected function getExtraData(): array
    {
        return [
            'size' => $this->size,
        ];
    }
}
