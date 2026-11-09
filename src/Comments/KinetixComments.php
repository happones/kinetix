<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\RecordReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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

    /**
     * The allowlisted model class a comments request is about: the record its
     * signed `reference` names, its legacy `commentable_type`, or the record
     * the comment being edited or deleted is on. Null when it names none (the
     * endpoint then rejects the request itself).
     *
     * For host middleware that gates comments per type, e.g. behind an
     * entitlement:
     *
     *     if (KinetixComments::commentableType($request) === Product::class) {
     *         KinetixEntitlements::authorize('products.review');
     *     }
     *
     * @return class-string<Model>|null
     */
    public static function commentableType(Request $request): ?string
    {
        $registry = static::registry();
        $comment  = $request->route('comment');

        if ($comment !== null) {
            $type = Comment::query()
                ->whereKey($comment instanceof Model ? $comment->getKey() : $comment)
                ->value('commentable_type');

            return is_string($type) ? $registry->resolve($type) : null;
        }

        $reference = $request->input('reference');

        if (is_string($reference) && $reference !== '') {
            return RecordReference::type($reference, $request, DescriptorAudience::Comments, $registry->resolve(...));
        }

        $type = $request->input('commentable_type');

        return is_string($type) ? $registry->resolve($type) : null;
    }
}
