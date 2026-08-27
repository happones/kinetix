<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One previously-used password HASH for a user.
 *
 * Only hashes are ever stored — the point of the table is to answer "have you
 * used this before?" without anyone (including an operator with database
 * access) being able to read what the old passwords were.
 *
 * @property int|string $id
 * @property int|string $user_id
 * @property string     $password
 * @property Carbon     $created_at
 */
class PasswordHistory extends Model
{
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
}
