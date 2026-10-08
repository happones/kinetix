<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One previously-used password HASH for a user.
 *
 * Only hashes are ever stored — the point of the table is to answer "have you
 * used this before?" without anyone (including an operator with database
 * access) being able to read what the old passwords were.
 *
 * A row belongs to an authenticatable by key AND model type: with credential
 * profiles, a `Client` #1 and a `User` #1 are different people, and must
 * never read or erase each other's history. Always query through {@see of()}.
 *
 * @property int|string  $id
 * @property int|string  $user_id
 * @property string|null $authenticatable_type
 * @property string      $password
 * @property Carbon      $created_at
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'kinetix_password_history';

    protected $guarded = [];

    /**
     * Whether the table has the `authenticatable_type` column, per connection.
     * Memoized like {@see PasswordObserver}'s column check, and flushed with it.
     *
     * @var array<string, bool>
     */
    protected static array $typeColumn = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * The history rows that belong to $user. Rows written before the type
     * column existed carry no type and belong to the default user model.
     * Until that column's migration has run, rows are matched by key alone —
     * the behaviour before credential profiles.
     *
     * @return Builder<static>
     */
    public static function of(Model $user): Builder
    {
        $query = static::query()->where('user_id', $user->getKey());

        if (! static::hasTypeColumn()) {
            return $query;
        }

        $type = $user->getMorphClass();

        return static::isDefaultModel($user)
            ? $query->where(static fn (Builder $q) => $q
                ->where('authenticatable_type', $type)
                ->orWhereNull('authenticatable_type'))
            : $query->where('authenticatable_type', $type);
    }

    /**
     * The attributes that tie a new row to $user.
     *
     * @return array<string, mixed>
     */
    public static function ownerAttributes(Model $user): array
    {
        return static::hasTypeColumn()
            ? ['user_id' => $user->getKey(), 'authenticatable_type' => $user->getMorphClass()]
            : ['user_id' => $user->getKey()];
    }

    protected static function isDefaultModel(Model $user): bool
    {
        $default = config('kinetix.credentials.user_model')
            ?: config('kinetix.membership.user_model', 'App\\Models\\User');

        return is_string($default) && $user instanceof $default;
    }

    protected static function hasTypeColumn(): bool
    {
        $instance   = new static;
        $connection = (string) $instance->getConnectionName();

        if (! array_key_exists($connection, static::$typeColumn)) {
            try {
                static::$typeColumn[$connection] = Schema::connection($instance->getConnectionName())
                    ->hasColumn($instance->getTable(), 'authenticatable_type');
            } catch (Throwable) {
                static::$typeColumn[$connection] = false;
            }
        }

        return static::$typeColumn[$connection];
    }

    /**
     * Drop the memoized schema check (start of a request / queued job, and
     * after a migration in tests).
     */
    public static function flush(): void
    {
        static::$typeColumn = [];
    }
}
