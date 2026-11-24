<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Forms\UploadDirectory;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\KinetixDisk;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class FileUpload extends Field
{
    /** Null = fall back to the global `kinetix.filesystem.disk` config. */
    protected ?string $disk = null;

    protected string $directory = 'uploads';

    /** Null = the global `kinetix.filesystem.visibility`, else the disk's default. */
    protected ?string $visibility = null;

    protected bool $isMultiple = false;

    /**
     * Accepted MIME types or extensions (for the input filter and validation).
     *
     * @var array<int, string>
     */
    protected array $acceptedFileTypes = [];

    protected ?int $maxSize = null;

    protected bool $isImage = false;

    protected ?int $maxFiles = null;

    protected function getType(): string
    {
        return 'file-upload';
    }

    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * The resolved disk: the explicit one, else the global filesystem default.
     */
    protected function resolveDisk(): string
    {
        return $this->disk ?? (string) config('kinetix.filesystem.disk', 'public');
    }

    /**
     * Store uploads 'public' or 'private'. A private file is shown through a
     * temporary signed URL (S3, or a local disk with `serve` on). Defaults to
     * `kinetix.filesystem.visibility`, else the disk's own default.
     */
    public function visibility(string $visibility): static
    {
        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException("Visibility must be 'public' or 'private', got '{$visibility}'.");
        }

        $this->visibility = $visibility;

        return $this;
    }

    /** The resolved visibility: the explicit one, else the global setting. */
    protected function resolveVisibility(): ?string
    {
        $visibility = $this->visibility ?? config('kinetix.filesystem.visibility');

        return in_array($visibility, ['public', 'private'], true) ? $visibility : null;
    }

    public function directory(string $directory): static
    {
        $this->directory = trim($directory, '/');

        return $this;
    }

    public function multiple(bool $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    /**
     * @param array<int, string> $types MIME types (e.g. 'application/pdf') or extensions (e.g. 'pdf').
     */
    public function acceptedFileTypes(array $types): static
    {
        $this->acceptedFileTypes = $types;

        return $this;
    }

    /**
     * Restrict to images and render thumbnail previews.
     */
    public function image(): static
    {
        $this->isImage = true;

        return $this;
    }

    /**
     * Maximum size per file in kilobytes.
     */
    public function maxSize(int $kilobytes): static
    {
        $this->maxSize = $kilobytes;

        return $this;
    }

    public function maxFiles(int $count): static
    {
        $this->maxFiles = $count;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        $data->isMultiple        = $this->isMultiple;
        $data->acceptedFileTypes = $this->acceptedFileTypes ?: null;
        $data->maxSize           = $this->maxSize;
        $data->isImage           = $this->isImage;
        $data->maxFiles          = $this->maxFiles;

        // Storage configuration is signed so the client cannot tamper with the
        // target disk/directory or bypass the file constraints on upload, and
        // bound to the user/team/expiry it was minted with.
        $data->uploadToken = SignedDescriptor::seal([
            'disk'       => $this->resolveDisk(),
            'directory'  => $this->directory,
            'visibility' => $this->resolveVisibility(),
            'accept'     => $this->acceptedFileTypes,
            'maxSize'    => $this->maxSize,
            'image'      => $this->isImage,
            'multiple'   => $this->isMultiple,
        ], DescriptorAudience::Upload);

        $data->previewUrls = $this->previewUrls($record);

        return $data;
    }

    /**
     * The URL each stored path of the current value is previewed through,
     * resolved on the field's disk — never guessed by the browser — so any
     * disk (S3, a custom URL) and private files (temporary URLs) preview.
     *
     * A private field only signs a path the record itself stores or one in the
     * current user's own upload directory: a live re-render feeds the value
     * back from the browser, and a crafted path must not come back signed.
     *
     * Null when the value holds no path; otherwise the map, empty when no path
     * may be previewed (the browser then shows a file tile, never a guess).
     *
     * @return array<string, string>|null
     */
    protected function previewUrls(?Model $record): ?array
    {
        $state   = $this->reactiveState[$this->getName()] ?? null;
        $paths   = array_filter(is_array($state) ? $state : [$state], static fn ($path): bool => is_string($path) && $path !== '');
        $private = KinetixDisk::isPrivate($this->resolveVisibility());
        $disk    = $this->resolveDisk();
        $urls    = [];

        if ($paths === []) {
            return null;
        }

        $stored  = $record !== null ? (array) data_get($record, $this->getName()) : [];
        $uploads = $private ? UploadDirectory::current($this->directory) : null;

        foreach ($paths as $path) {
            if (preg_match('#^(https?:)?//|^data:|^/#i', $path) === 1) {
                $urls[$path] = $path;

                continue;
            }

            if ($private && ! in_array($path, $stored, true) && ! UploadDirectory::contains((string) $uploads, $path)) {
                continue;
            }

            $urls[$path] = KinetixDisk::url($disk, $path, $private);
        }

        return $urls;
    }
}
