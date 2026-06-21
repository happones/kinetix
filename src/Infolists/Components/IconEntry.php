<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasIcon;
use Illuminate\Database\Eloquent\Model;

class IconEntry extends Entry
{
    protected bool $isBoolean = false;

    /**
     * Icon mapping of icon name => Closure|value.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Color mapping of color name => Closure|value.
     *
     * @var array<string, mixed>
     */
    protected array $colors = [];

    protected int|string $size = 24;

    protected function getType(): string
    {
        return 'icon';
    }

    public function boolean(): static
    {
        $this->isBoolean = true;

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

    public function size(int|string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getIcon(?Model $record = null): ?string
    {
        $raw = $this->getRawState($record);

        if ($raw instanceof HasIcon) {
            return $raw->getIcon();
        }

        if ($this->isBoolean) {
            return (bool) $raw ? 'check-circle' : 'x-circle';
        }

        if ($this->icon instanceof Closure) {
            $resolved = ($this->icon)($this->getState($record), $record);

            return $resolved !== null ? (string) $resolved : null;
        }

        foreach ($this->options as $icon => $condition) {
            if ($condition instanceof Closure) {
                if ($condition($raw, $record)) {
                    return $icon;
                }
            } elseif ($condition === $raw) {
                return $icon;
            }
        }

        return is_string($this->icon) ? $this->icon : null;
    }

    public function getColor(?Model $record = null): ?string
    {
        $raw = $this->getRawState($record);

        if ($raw instanceof HasColor) {
            return $raw->getColor() ?? 'gray';
        }

        if ($this->isBoolean) {
            return (bool) $raw ? 'success' : 'danger';
        }

        if ($this->color instanceof Closure) {
            $resolved = ($this->color)($this->getState($record), $record);

            return $resolved !== null ? (string) $resolved : null;
        }

        foreach ($this->colors as $color => $condition) {
            if ($condition instanceof Closure) {
                if ($condition($raw, $record)) {
                    return $color;
                }
            } elseif ($condition === $raw) {
                return $color;
            }
        }

        return is_string($this->color) ? $this->color : 'gray';
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'size' => $this->size,
        ];
    }
}
