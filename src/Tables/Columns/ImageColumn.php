<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImageColumn extends Column
{
    protected bool $isCircular = false;

    protected bool $isPreviewable = false;

    protected int|string $size = 40;

    protected string|Closure|null $defaultImageUrl = null;

    /** Null = fall back to the global `kinetix.filesystem.disk` config. */
    protected ?string $disk = null;

    protected function getType(): string
    {
        return 'image';
    }

    /**
     * Resolve stored image paths through this disk (e.g. 's3'). Defaults to the
     * global `kinetix.filesystem.disk`. Full URLs (http/https/data/leading "/")
     * are left untouched.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Make the thumbnail clickable to open a zoomable preview in a lightbox.
     */
    public function preview(bool $condition = true): static
    {
        $this->isPreviewable = $condition;

        return $this;
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

        return $this->resolveUrl((string) $state);
    }

    /**
     * Turn a stored path into a public URL via the configured disk. Values that
     * are already absolute (http/https/data URIs or a leading "/") pass through.
     */
    protected function resolveUrl(string $value): string
    {
        if (preg_match('#^(https?:)?//|^data:|^/#i', $value) === 1) {
            return $value;
        }

        $disk = $this->disk ?? (string) config('kinetix.filesystem.disk', 'public');

        return Storage::disk($disk)->url($value);
    }

    protected function getExtraData(): array
    {
        return [
            'isCircular'    => $this->isCircular,
            'isPreviewable' => $this->isPreviewable,
            'size'          => $this->size,
        ];
    }
}
