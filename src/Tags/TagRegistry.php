<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The allowlist of models that can be tagged from the public endpoints. The host
 * registers them with `KinetixTags::for([Post::class, ...])`. Bound as a
 * singleton.
 */
class TagRegistry
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
     * Resolve a registered, taggable model class from the client type (morph
     * alias or class), or null when it isn't allowlisted or lacks the trait.
     *
     * @return class-string<Model>|null
     */
    public function resolve(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        foreach ($this->models as $allowed) {
            if (($allowed === $class || $allowed === $type)
                && in_array(HasKinetixTags::class, class_uses_recursive($allowed), true)) {
                return $allowed;
            }
        }

        return null;
    }
}
