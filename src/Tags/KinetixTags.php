<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for the Tags feature. Declare which (HasKinetixTags) models
 * can be tagged from the endpoints:
 *
 *     KinetixTags::for([Post::class, Task::class]);
 */
class KinetixTags
{
    public static function registry(): TagRegistry
    {
        return app(TagRegistry::class);
    }

    /**
     * @param array<int, class-string<Model>> $models
     */
    public static function for(array $models): void
    {
        static::registry()->register($models);
    }
}
