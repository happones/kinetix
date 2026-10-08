<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Happones\Kinetix\Support\Concerns\OwnedByModelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
    use OwnedByModelType;

    public const UPDATED_AT = null;

    protected $table = 'kinetix_password_history';

    protected $guarded = [];

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
        return static::ownedBy($user);
    }

    /**
     * Drop the memoized schema check (start of a request / queued job, and
     * after a migration in tests).
     */
    public static function flush(): void
    {
        static::flushOwnerTypeColumn();
    }

    protected static function ownerTypeColumn(): string
    {
        return 'authenticatable_type';
    }
}
