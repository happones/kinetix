<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Illuminate\Database\Eloquent\Model;

class ImageEntry extends Entry
{
    protected bool $isCircular = false;

    protected int|string $size = 96;

    protected string|Closure|null $defaultImageUrl = null;

    protected function getType(): string
    {
        return 'image';
    }

    public function circular(): static
    {
        $this->isCircular = true;

        return $this;
    }

    public function square(): static
    {
        $this->isCircular = false;

        return $this;
    }

    public function size(int|string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function defaultImageUrl(string|Closure $url): static
    {
        $this->defaultImageUrl = $url;

        return $this;
    }

    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if ($value === null || $value === '') {
            if ($this->defaultImageUrl instanceof Closure) {
                return ($this->defaultImageUrl)($record);
            }

            return $this->defaultImageUrl;
        }

        return $value;
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'isCircular' => $this->isCircular,
            'size'       => $this->size,
        ];
    }
}
