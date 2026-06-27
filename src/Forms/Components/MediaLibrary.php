<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A multi-file media manager: upload many files, see a thumbnail grid, drag to
 * reorder, and delete. Builds on {@see FileUpload} (same signed upload token /
 * disk / constraints) and adds a `collection` + image `conversions`.
 *
 * Works standalone (the field value is an ordered array of media items, stored
 * however you like) and integrates with **spatie/laravel-medialibrary** when it
 * is installed and the form's record implements `HasMedia`: hydrate the field
 * with `KinetixMedia::items($record, $collection)` and persist on save with
 * `KinetixMedia::sync($record, $collection, $state, $disk)`.
 */
class MediaLibrary extends FileUpload
{
    protected bool $isMultiple = true;

    protected bool $isReorderable = true;

    protected string $collection = 'default';

    /**
     * Conversion names whose URLs are exposed to the grid (spatie).
     *
     * @var array<int, string>
     */
    protected array $conversions = [];

    protected function getType(): string
    {
        return 'media-library';
    }

    /**
     * The media collection name (spatie) / logical grouping (native).
     */
    public function collection(string $name): static
    {
        $this->collection = $name;

        return $this;
    }

    /**
     * Image conversion names to surface in the grid (requires spatie).
     *
     * @param array<int, string> $conversions
     */
    public function conversions(array $conversions): static
    {
        $this->conversions = $conversions;

        return $this;
    }

    public function reorderable(bool $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function getCollection(): string
    {
        return $this->collection;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->mediaCollection  = $this->collection;
        $data->mediaConversions = $this->conversions === [] ? null : $this->conversions;
        $data->isReorderable    = $this->isReorderable;

        return $data;
    }
}
