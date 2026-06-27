<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Add to any model to make it taggable with Kinetix tags:
 *
 *     class Post extends Model {
 *         use HasKinetixTags;
 *     }
 *
 *     $post->tags;                  // the attached Tag models
 *     $post->tags()->pluck('name'); // their names
 */
trait HasKinetixTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(
            Tag::class,
            'taggable',
            'kinetix_taggables',
            'taggable_id',
            'tag_id',
        );
    }
}
