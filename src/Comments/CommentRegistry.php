<?php

declare(strict_types=1);

namespace Happones\Kinetix\Comments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The allowlist of models that accept comments. The host registers them with
 * `KinetixComments::for([Post::class, Task::class])`; only these (by class or
 * their morph alias) can be commented on, which keeps the public endpoints from
 * reaching arbitrary records. Bound as a singleton.
 */
class CommentRegistry
{
    /**
     * @var array<int, class-string<Model>>
     */
    protected array $models = [];

    /**
     * @param array<int, class-string<Model>> $models
     */
    public function register(array $models): void
    {
        foreach ($models as $model) {
            $this->models[] = $model;
        }
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Resolve a registered model class from the type sent by the client (its
     * morph alias or class name), or null when it is not allowlisted.
     *
     * @return class-string<Model>|null
     */
    public function resolve(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        foreach ($this->models as $allowed) {
            if ($allowed === $class || $allowed === $type) {
                return $allowed;
            }
        }

        return null;
    }
}
