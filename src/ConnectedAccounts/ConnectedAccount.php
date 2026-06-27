<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's linked OAuth identity (one row per user+provider). The access /
 * refresh tokens are encrypted at rest. Kinetix owns this table; the User model
 * needs no trait — accounts are queried by `user_id`.
 *
 * @property int|string  $id
 * @property int|string  $user_id
 * @property string      $provider
 * @property string      $provider_id
 * @property string|null $nickname
 * @property string|null $name
 * @property string|null $email
 * @property string|null $avatar
 * @property string|null $token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class ConnectedAccount extends Model
{
    protected $table = 'kinetix_connected_accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token'         => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at'    => 'datetime',
        ];
    }
}
