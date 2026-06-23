<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class FileUpload extends Field
{
    /** Null = fall back to the global `kinetix.filesystem.disk` config. */
    protected ?string $disk = null;

    protected string $directory = 'uploads';

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
        // target disk/directory or bypass the file constraints on upload.
        $data->uploadToken = Crypt::encrypt([
            'disk'      => $this->resolveDisk(),
            'directory' => $this->directory,
            'accept'    => $this->acceptedFileTypes,
            'maxSize'   => $this->maxSize,
            'image'     => $this->isImage,
            'multiple'  => $this->isMultiple,
        ]);

        return $data;
    }
}
