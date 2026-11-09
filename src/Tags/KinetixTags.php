<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\RecordReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    /**
     * The allowlisted model class a tags request is about: the record its
     * signed `reference` names, or its legacy `taggable_type`. Null when it
     * names none (suggestions name no record). For host middleware that gates
     * tagging per type.
     *
     * @return class-string<Model>|null
     */
    public static function taggableType(Request $request): ?string
    {
        $registry  = static::registry();
        $reference = $request->input('reference');

        if (is_string($reference) && $reference !== '') {
            return RecordReference::type($reference, $request, DescriptorAudience::Tags, $registry->resolve(...));
        }

        $type = $request->input('taggable_type');

        return is_string($type) ? $registry->resolve($type) : null;
    }
}
