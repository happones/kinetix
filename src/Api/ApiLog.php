<?php

declare(strict_types=1);

namespace Happones\Kinetix\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One logged API request (see the `kinetix.api-log` middleware). Append-only:
 * no updated_at, pruned by `kinetix:api-logs:prune`.
 *
 * @property int                       $id
 * @property int|null                  $user_id
 * @property int|null                  $token_id
 * @property string|null               $token_name
 * @property string                    $method
 * @property string                    $path
 * @property int                       $status
 * @property int|null                  $duration_ms
 * @property string|null               $ip
 * @property array<string, mixed>|null $request_body
 * @property string|null               $response_body
 * @property Carbon|null               $created_at
 */
class ApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'kinetix_api_logs';

    protected $guarded = [];

    protected $casts = [
        'request_body' => 'array',
        'created_at'   => 'datetime',
    ];
}
