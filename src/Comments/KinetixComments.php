<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for the Comments feature. Declare which models accept
 * comments in a service provider:
 *
 *     KinetixComments::for([Post::class, Task::class]);
 */
class KinetixComments
{
    public static function registry(): CommentRegistry
    {
        return app(CommentRegistry::class);
    }

    /**
     * @param array<int, class-string<Model>> $models
     */
    public static function for(array $models): void
    {
        static::registry()->register($models);
    }
}
