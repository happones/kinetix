<?php

declare(strict_types=1);

namespace Happones\Kinetix\Media;

use Illuminate\Database\Eloquent\Model;

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
    public function sync(Model $record, string $collection, array $items, ?string $disk = null): void
    {
        if (! $this->usesSpatie($record)) {
            return;
        }

        $disk ??= (string) config('kinetix.filesystem.disk', 'public');

        // Add new uploads / keep existing, building the desired order of ids.
        $orderedIds = [];
        foreach ($items as $item) {
            $id   = $item['id']   ?? null;
            $path = $item['path'] ?? null;

            if ($id !== null && $id !== '') {
                $orderedIds[] = $id;
            } elseif (is_string($path) && $path !== '') {
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

        // Persist the order.
        if ($orderedIds !== []) {
            $mediaClass = $record->media()->getRelated();
            $mediaClass::setNewOrder($orderedIds);
        }
    }
}
