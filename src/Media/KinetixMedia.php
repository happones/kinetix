<?php

declare(strict_types=1);

namespace Happones\Kinetix\Media;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for wiring a <KinetixMediaLibrary> field to a record's
 * spatie media collection:
 *
 *     // hydrate the field
 *     $form->fill(['gallery' => KinetixMedia::items($product, 'images', 'thumb')]);
 *
 *     // persist on save
 *     KinetixMedia::sync($product, 'images', $state['gallery']);
 */
class KinetixMedia
{
    public static function manager(): MediaManager
    {
        return app(MediaManager::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function items(Model $record, string $collection = 'default', ?string $conversion = null): array
    {
        return static::manager()->items($record, $collection, $conversion);
    }

    /**
     * Reconcile a spatie collection with the submitted items. Pass the field's
     * `directory()`: a new file is only accepted from the current user's
     * uploads for it.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function sync(Model $record, string $collection, array $items, ?string $disk = null, ?string $directory = null): void
    {
        static::manager()->sync($record, $collection, $items, $disk, $directory);
    }

    /**
     * Without spatie: the submitted items safe to store in your own column
     * (see {@see MediaManager::keep()}).
     *
     * @param  array<int, mixed>                $items
     * @param  array<int, mixed>                $current
     * @return array<int, array<string, mixed>>
     */
    public static function keep(array $items, array $current = [], ?string $directory = null, ?string $disk = null): array
    {
        return static::manager()->keep($items, $current, $directory, $disk);
    }
}
