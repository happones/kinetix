<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

/**
 * Static entry point for declaring product tours, typically from a service
 * provider:
 *
 *     KinetixTours::tour('posts')
 *         ->page('Kinetix/Posts/Index')
 *         ->permission('posts.viewAny')
 *         ->steps([
 *             TourStep::make('[data-tour=create]')
 *                 ->title(__('tours.posts.create'))
 *                 ->description(__('tours.posts.create_body')),
 *         ]);
 */
class KinetixTours
{
    public static function registry(): TourRegistry
    {
        return app(TourRegistry::class);
    }

    public static function tour(string $id): Tour
    {
        return static::registry()->tour($id);
    }
}
