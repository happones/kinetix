<?php

declare(strict_types=1);

namespace Happones\Kinetix\Media;

use Happones\Kinetix\Forms\UploadDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Bridges the <KinetixMediaLibrary> field to spatie/laravel-medialibrary when it
 * is installed and the record implements `HasMedia`. Without spatie (or for a
 * non-media record) it is a no-op and the field value is just an ordered array
 * of items you persist yourself.
 */
class MediaManager
{
    /**
     * Whether spatie media-library can drive this record.
     */
    public function usesSpatie(Model $record): bool
    {
        return interface_exists('Spatie\MediaLibrary\HasMedia')
            && is_a($record, 'Spatie\MediaLibrary\HasMedia');
    }

    /**
     * The media items in a collection, shaped for the field. Empty without spatie.
     *
     * @return array<int, array{id: int|string, uuid: string|null, name: string, url: string, thumb: string, size: int|null, mime: string|null}>
     */
    public function items(Model $record, string $collection = 'default', ?string $conversion = null): array
    {
        if (! $this->usesSpatie($record)) {
            return [];
        }

        return $record->getMedia($collection)
            ->map(fn ($media): array => [
                'id'    => $media->getKey(),
                'uuid'  => $media->uuid,
                'name'  => $media->file_name,
                'url'   => $media->getUrl(),
                'thumb' => $conversion !== null && $media->hasGeneratedConversion($conversion)
                    ? $media->getUrl($conversion)
                    : $media->getUrl(),
                'size' => $media->size,
                'mime' => $media->mime_type,
            ])
            ->values()
            ->all();
    }

    /**
     * Reconcile a collection with the field's ordered state: add newly uploaded
     * files (items with a `path` but no `id`), drop removed ones, and persist the
     * new order. No-op without spatie.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function sync(
        Model $record,
        string $collection,
        array $items,
        ?string $disk = null,
        ?string $directory = null,
    ): void {
        if (! $this->usesSpatie($record)) {
            return;
        }

        $disk ??= (string) config('kinetix.filesystem.disk', 'public');

        // A new file can only be one the current user just uploaded for this
        // field: their own upload directory, never anywhere on the disk.
        $uploads = UploadDirectory::for($directory, auth()->user()?->getAuthIdentifier());

        // `$items` reflects submitted form state, so both the ids and the paths in
        // it are client-controlled. Existing media is therefore matched against
        // what this record actually owns, and new files must sit inside the
        // field's own directory — otherwise a crafted `path` could pull an
        // unrelated file (say, someone's export) into this collection, and a
        // crafted `id` could adopt or reorder another record's media.
        $ownedIds = $record->getMedia($collection)
            ->map(static fn ($media) => (string) $media->getKey())
            ->all();

        $orderedIds = [];

        foreach ($items as $item) {
            $id   = $item['id']   ?? null;
            $path = $item['path'] ?? null;

            if ($id !== null && $id !== '') {
                if (in_array((string) $id, $ownedIds, true)) {
                    $orderedIds[] = $id;
                }

                continue;
            }

            if (is_string($path) && $path !== '' && UploadDirectory::contains($uploads, $path)) {
                $media        = $record->addMediaFromDisk($path, $disk)->toMediaCollection($collection);
                $orderedIds[] = $media->getKey();
            }
        }

        // Remove any media no longer present in the state.
        $record->getMedia($collection)->each(function ($media) use ($orderedIds): void {
            if (! in_array($media->getKey(), $orderedIds, true)) {
                $media->delete();
            }
        });

        // Persist the order. spatie's setNewOrder() is not record-scoped, so only
        // ids verified above as belonging to this record are handed to it.
        if ($orderedIds !== []) {
            $mediaClass = $record->media()->getRelated();
            $mediaClass::setNewOrder($orderedIds);
        }
    }

    /**
     * The submitted items safe to store in your own column, when spatie isn't
     * in play: an item already stored ($current, matched by path) keeps its
     * stored data; a new one must be a file the current user uploaded for this
     * field (their upload directory) that exists. Name, size, mime and URL
     * come from the disk, never the request. Order follows $items.
     *
     * @param  array<int, mixed>                $items   submitted, client-controlled
     * @param  array<int, mixed>                $current what the record stores
     * @return array<int, array<string, mixed>>
     */
    public function keep(array $items, array $current = [], ?string $directory = null, ?string $disk = null): array
    {
        $disk ??= (string) config('kinetix.filesystem.disk', 'public');
        $uploads = UploadDirectory::for($directory, auth()->user()?->getAuthIdentifier());
        $storage = Storage::disk($disk);
        $stored  = collect($current)->filter(fn ($item): bool => is_array($item) && is_string($item['path'] ?? null))->keyBy('path');
        $kept    = [];

        foreach ($items as $item) {
            $path = is_array($item) ? ($item['path'] ?? null) : null;

            if (! is_string($path) || $path === '' || isset($kept[$path])) {
                continue;
            }

            if ($stored->has($path)) {
                $kept[$path] = $stored->get($path);

                continue;
            }

            if (UploadDirectory::contains($uploads, $path) && $storage->exists($path)) {
                $kept[$path] = [
                    'path' => $path,
                    'url'  => $storage->url($path),
                    'name' => basename($path),
                    'size' => $storage->size($path),
                    'mime' => $storage->mimeType($path) ?: null,
                ];
            }
        }

        return array_values($kept);
    }
}
