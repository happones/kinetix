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
            return (bool) $state ? 'check-circle' : 'x-circle';
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
            return (bool) $state ? 'success' : 'danger';
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
}
