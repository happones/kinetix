<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rows that belong to one model by key AND model type. With credential
 * profiles, a `Client` #1 and a `User` #1 are different people; a row keyed by
 * id alone would be shared between them. Always query through
 * {@see ownedBy()} and write new rows with {@see ownerAttributes()}.
 *
 * Rows written before the type column existed carry no type and belong to the
 * default user model. Rows written under the owner's class name still belong
 * to it after a morph map gives that class an alias. Until the type column's
 * migration has run, rows are matched by key alone.
 */
trait OwnedByModelType
{
    /**
     * Whether the table has the type column, per connection. Memoized, and
     * flushed at the start of every request and queued job.
     *
     * @var array<string, bool>
     */
    protected static array $ownerTypeColumnExists = [];

    /**
     * The column holding the owner's morph class.
     */
    abstract protected static function ownerTypeColumn(): string;

    /**
     * The column holding the owner's key.
     */
    protected static function ownerKeyColumn(): string
    {
        return 'user_id';
    }

    /**
     * The rows that belong to $owner.
     *
     * @return Builder<static>
     */
    public static function ownedBy(Model $owner): Builder
    {
        $query = static::query()->where(static::ownerKeyColumn(), $owner->getKey());

        if (! static::hasOwnerTypeColumn()) {
            return $query;
        }

        $column = static::ownerTypeColumn();
        $types  = array_values(array_unique([$owner->getMorphClass(), $owner::class]));

        return static::isDefaultOwner($owner)
            ? $query->where(static fn (Builder $q) => $q
                ->whereIn($column, $types)
                ->orWhereNull($column))
            : $query->whereIn($column, $types);
    }

    /**
     * The attributes that tie a row to $owner.
     *
     * @return array<string, mixed>
     */
    public static function ownerAttributes(Model $owner): array
    {
        return static::hasOwnerTypeColumn()
            ? [static::ownerKeyColumn() => $owner->getKey(), static::ownerTypeColumn() => $owner->getMorphClass()]
            : [static::ownerKeyColumn() => $owner->getKey()];
    }

    /**
     * Drop the memoized schema check (start of a request / queued job, and
     * after a migration in tests).
     */
    public static function flushOwnerTypeColumn(): void
    {
        static::$ownerTypeColumnExists = [];
    }

    /**
     * Whether $owner is the default user model, which owns the untyped rows:
     * `credentials.user_model` when set, else `membership.user_model` or the
     * default guard's provider model — the user those rows were written for
     * when one model was all there was.
     */
    protected static function isDefaultOwner(Model $owner): bool
    {
        $configured = config('kinetix.credentials.user_model');

        if (is_string($configured) && $configured !== '') {
            $defaults = [$configured];
        } else {
            $guard    = config('auth.defaults.guard', 'web');
            $provider = config("auth.guards.{$guard}.provider", 'users');
            $defaults = [
                config('kinetix.membership.user_model', 'App\\Models\\User'),
                config("auth.providers.{$provider}.model"),
            ];
        }

        foreach ($defaults as $default) {
            if (is_string($default) && $owner instanceof $default) {
                return true;
            }
        }

        return false;
    }

    protected static function hasOwnerTypeColumn(): bool
    {
        $instance   = new static;
        $connection = (string) $instance->getConnectionName();

        if (! array_key_exists($connection, static::$ownerTypeColumnExists)) {
            try {
                static::$ownerTypeColumnExists[$connection] = Schema::connection($instance->getConnectionName())
                    ->hasColumn($instance->getTable(), static::ownerTypeColumn());
            } catch (Throwable) {
                static::$ownerTypeColumnExists[$connection] = false;
            }
        }

        return static::$ownerTypeColumnExists[$connection];
    }
}
