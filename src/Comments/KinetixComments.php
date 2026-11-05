<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\RecordReference;
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

    /**
     * The signed reference a page hands `<KinetixComments :reference>` so the
     * endpoints load exactly this record. It is bound to the current user and
     * team and expires with `kinetix.tables.token_ttl`, so mint it on every
     * page render.
     */
    public static function reference(Model $model): string
    {
        return RecordReference::seal($model, DescriptorAudience::Comments);
    }
}
