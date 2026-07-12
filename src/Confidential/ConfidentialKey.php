<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One generation of the confidential-fields keyring — a wrapped Data
 * Encryption Key (DEK), keyed by `key_id`. Every encrypted attribute
 * envelope embeds the `key_id` it was encrypted under, so retired keys
 * stay resolvable for as long as their ciphertext exists.
 *
 * @property int         $id
 * @property string      $key_id
 * @property string      $driver
 * @property string      $wrapped_key
 * @property bool        $is_current
 * @property Carbon|null $retired_at
 */
class ConfidentialKey extends Model
{
    protected $table = 'kinetix_confidential_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }
}
