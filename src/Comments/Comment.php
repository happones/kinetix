<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A comment authored by a user on any model (polymorphic), optionally a threaded
 * reply to another comment.
 *
 * @property int|string      $id
 * @property int|string      $user_id
 * @property string          $commentable_type
 * @property int|string      $commentable_id
 * @property int|string|null $parent_id
 * @property string          $body
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 */
class Comment extends Model
{
    protected $table = 'kinetix_comments';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    /**
     * The comment author (the configured authenticatable model).
     *
     * @return BelongsTo<Model, $this>
     */
    public function author(): BelongsTo
    {
        $guard    = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');

        /** @var class-string<Model> $model */
        $model = config("auth.providers.{$provider}.model", 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }
}
