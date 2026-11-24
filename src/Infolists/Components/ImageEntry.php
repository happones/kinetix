<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Closure;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ImageEntry extends Entry
{
    protected bool $isCircular = false;

    protected bool $isPreviewable = false;

    protected int|string $size = 96;

    protected string|Closure|null $defaultImageUrl = null;

    /** Null = fall back to the global `kinetix.filesystem.disk` config. */
    protected ?string $disk = null;

    /** Null = the global `kinetix.filesystem.visibility`. */
    protected ?string $visibility = null;

    protected function getType(): string
    {
        return 'image';
    }

    /**
     * Resolve stored image paths through this disk (defaults to the global
     * `kinetix.filesystem.disk`). Absolute URLs pass through untouched.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * 'private' shows stored paths through temporary signed URLs (S3, or a
     * local disk with `serve` on) instead of plain public URLs. Defaults to
     * `kinetix.filesystem.visibility`.
     */
    public function visibility(string $visibility): static
    {
        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException("Visibility must be 'public' or 'private', got '{$visibility}'.");
        }

        $this->visibility = $visibility;

        return $this;
    }

    /**
     * Make the image clickable to open it in the lightbox. Previewable images
     * of the same infolist browse as one gallery.
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

    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if ($value === null || $value === '') {
            if ($this->defaultImageUrl instanceof Closure) {
                return ($this->defaultImageUrl)($record);
            }

            return $this->defaultImageUrl;
        }

        return $this->resolveUrl((string) $value);
    }

    /**
     * Turn a stored path into a public URL via the configured disk. Absolute
     * values (http(s)://, //, /…, data:) pass through untouched.
     */
    protected function resolveUrl(string $value): string
    {
        if (preg_match('#^(https?:)?//|^data:|^/#i', $value) === 1) {
            return $value;
        }

        $disk = $this->disk ?? (string) config('kinetix.filesystem.disk', 'public');

        return KinetixDisk::url($disk, $value, KinetixDisk::isPrivate($this->visibility));
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'isCircular'    => $this->isCircular,
            'isPreviewable' => $this->isPreviewable,
            'size'          => $this->size,
        ];
    }
}
