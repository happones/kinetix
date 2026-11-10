<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * People as `[model type, key]` pairs a queued job carries, instead of
 * models: the worker loads them fresh, a few queries for any number of them.
 *
 * @internal
 */
final class UserRefs
{
    /**
     * @return array{0: string, 1: int|string}
     */
    public static function of(Model $user): array
    {
        /** @var int|string $key */
        $key = $user->getKey();

        return [$user->getMorphClass(), $key];
    }

    /**
     * @param  iterable<Model>                       $users
     * @return list<array{0: string, 1: int|string}>
     */
    public static function ofMany(iterable $users): array
    {
        $refs = [];

        foreach ($users as $user) {
            $refs[] = self::of($user);
        }

        return $refs;
    }

    /**
     * The people the pairs name that still exist, one query per model type.
     *
     * @param  list<array{0: string, 1: int|string}> $refs
     * @return Collection<int, Model>
     */
    public static function load(array $refs): Collection
    {
        $byType = [];

        foreach ($refs as [$type, $key]) {
            $byType[$type][] = $key;
        }

        $users = new Collection;

        foreach ($byType as $type => $keys) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = new $class;

            $users = $users->concat($class::query()->whereIn($model->getQualifiedKeyName(), $keys)->get());
        }

        return $users->values();
    }
}
