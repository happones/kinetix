<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;

class ImageColumn extends Column
{
    protected bool $isCircular = false;

    protected int|string $size = 40;

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

    public function getImageUrl(Model $record): ?string
    {
        $state = $this->getState($record);

        if ($state === null || $state === '') {
            if ($this->defaultImageUrl instanceof Closure) {
                return ($this->defaultImageUrl)($record);
            }

            return $this->defaultImageUrl;
        }

        return $state;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'isCircular' => $this->isCircular,
            'size'       => $this->size,
        ]);
    }
}
