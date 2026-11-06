<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Data\ActivityData;
use Happones\Kinetix\Support\SensitiveKeys;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * The ONE rule for which attribute values stay out of the activity log:
 * hidden attributes, the ones stored encrypted (`encrypted*` casts, the
 * encrypted ArrayObject / Collection casts, ConfidentialCast), `password`
 * and `remember_token`.
 *
 * Write time ({@see Concerns\LogsKinetixActivity}) and read time
 * ({@see ActivityData}) both ask it, so rows written
 * before redaction existed are redacted when they are shown.
 *
 * @internal
 */
final class ActivityRedactor
{
    /** What a redacted value becomes. */
    public const REDACTED = '[redacted]';

    /** @var list<string> */
    private const ALWAYS = ['password', 'remember_token'];

    /**
     * A blank instance per subject class (false: not a model), so read time
     * costs one instantiation per class per process, and no query.
     *
     * @var array<string, Model|false>
     */
    private static array $instances = [];

    /** Whether `$attribute` of `$model` stays out of the log. */
    public static function redacts(Model $model, string $attribute): bool
    {
        if (in_array($attribute, self::ALWAYS, true) || in_array($attribute, $model->getHidden(), true)) {
            return true;
        }

        if (ConfidentialCast::appliesTo($model, $attribute)) {
            return true;
        }

        $cast = $model->getCasts()[$attribute] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        if (str_starts_with(strtolower($cast), 'encrypted')) {
            return true;
        }

        $class = explode(':', $cast, 2)[0];

        return in_array($class, [AsEncryptedArrayObject::class, AsEncryptedCollection::class], true);
    }

    /**
     * `$attributes` as they may be shown for a subject of `$subjectType` (a
     * class or a morph alias): every redacted value becomes
     * {@see REDACTED}. A subject type that doesn't resolve to a model falls
     * back to the secret-looking key rule ({@see SensitiveKeys::matches()}).
     *
     * Resolving a class instantiates it once (with its constructor: casts
     * are set up there), so a model's constructor must not have side
     * effects.
     *
     * @param  array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    public static function redactFor(?string $subjectType, array $attributes): array
    {
        if ($attributes === []) {
            return $attributes;
        }

        $model = self::instanceFor($subjectType);

        foreach (array_keys($attributes) as $key) {
            if ($attributes[$key] === self::REDACTED) {
                continue;
            }

            $redacts = $model instanceof Model
                ? self::redacts($model, (string) $key)
                : (in_array((string) $key, self::ALWAYS, true) || SensitiveKeys::matches($key));

            if ($redacts) {
                $attributes[$key] = self::REDACTED;
            }
        }

        return $attributes;
    }

    /** Forget the memoized instances (tests). */
    public static function flush(): void
    {
        self::$instances = [];
    }

    private static function instanceFor(?string $subjectType): ?Model
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        if (! array_key_exists($subjectType, self::$instances)) {
            self::$instances[$subjectType] = self::make($subjectType);
        }

        $instance = self::$instances[$subjectType];

        return $instance instanceof Model ? $instance : null;
    }

    private static function make(string $subjectType): Model|false
    {
        $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        try {
            return new $class;
        } catch (Throwable) {
            return false;
        }
    }
}
