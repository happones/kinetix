<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Resolves a relation BY NAME without ever calling a method that is not one.
 *
 * Calling `$model->{$name}()` for a stored or client-supplied name is an
 * arbitrary method call (`delete`, `forceDelete`, `save`…), and
 * `Model::isRelation()` does not help: it is true for `delete`. A name only
 * resolves when the method:
 *
 * 1. exists, is public, non-static and takes no required parameter;
 * 2. is not a framework method: neither declared by an `Illuminate\` class
 *    (an app override of one included) nor defined by an `Illuminate\` trait
 *    the model uses (`SoftDeletes::forceDelete`, `restore`…);
 * 3. declares only Relation return types when it declares one — anything
 *    else is refused WITHOUT being called;
 * 4. returns a Relation when it is untyped (it is called to find out).
 *
 * @internal
 */
final class EloquentRelation
{
    /**
     * The relation `$name` names on `$model`, or null when it names none.
     *
     * @return Relation<Model, Model, mixed>|null
     */
    public static function resolve(Model $model, string $name): ?Relation
    {
        if ($name === '' || ! method_exists($model, $name)) {
            return null;
        }

        $method = new ReflectionMethod($model, $name);

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        if (self::isFrameworkMethod($model, $name, $method)) {
            return null;
        }

        $type = $method->getReturnType();

        if ($type !== null && ! self::declaresOnlyRelations($type)) {
            return null;
        }

        $relation = $model->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * Whether the method belongs to the framework: declared (or overridden
     * from a declaration) in an `Illuminate\` class, or defined by an
     * `Illuminate\` trait the model uses.
     */
    private static function isFrameworkMethod(Model $model, string $name, ReflectionMethod $method): bool
    {
        if (self::isFrameworkName($method->getDeclaringClass()->getName())) {
            return true;
        }

        foreach (class_parents($model) as $parent) {
            if (self::isFrameworkName($parent) && method_exists($parent, $name)) {
                return true;
            }
        }

        foreach (class_uses_recursive($model) as $trait) {
            if (self::isFrameworkName($trait) && method_exists($trait, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function isFrameworkName(string $class): bool
    {
        return str_starts_with($class, 'Illuminate\\');
    }

    /**
     * Whether every named type of a declared return type is a Relation
     * (`null` aside, so `?HasMany` and `HasMany|BelongsTo|null` pass).
     */
    private static function declaresOnlyRelations(ReflectionType $type): bool
    {
        $types = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        $relations = 0;

        foreach ($types as $member) {
            if (! $member instanceof ReflectionNamedType) {
                return false;
            }

            if ($member->getName() === 'null') {
                continue;
            }

            if ($member->isBuiltin() || ! is_a($member->getName(), Relation::class, true)) {
                return false;
            }

            $relations++;
        }

        return $relations > 0;
    }
}
