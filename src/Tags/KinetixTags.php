<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\RecordReference;
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

    /**
     * The signed reference a page hands `<KinetixTags :reference>` so the
     * endpoints load exactly this record. A reference minted with
     * `$editable = false` lists tags but cannot change them. It is bound to
     * the current user and team and expires with `kinetix.tables.token_ttl`,
     * so mint it on every page render.
     */
    public static function reference(Model $model, bool $editable = true): string
    {
        return RecordReference::seal($model, DescriptorAudience::Tags, ['editable' => $editable]);
    }
}
