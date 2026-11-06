<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer-registered webhook endpoint: a URL + signing secret + the set of
 * subscribed event names. Deliveries are logged in {@see WebhookLog}.
 *
 * @property int|string         $id
 * @property int|string|null    $team_id
 * @property string             $name
 * @property string             $url
 * @property string             $secret
 * @property array<int, string> $events
 * @property bool               $active
 * @property Carbon|null        $created_at
 */
class WebhookEndpoint extends Model
{
    protected $table = 'kinetix_webhook_endpoints';

    protected $guarded = [];

    /**
     * The signing secret never leaves the server in a serialized endpoint.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * The secret is encrypted at rest; one stored before that reads as it
     * is until `kinetix:webhooks:encrypt-secrets` encrypts it.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => EncryptedWithPlaintextFallback::class,
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<WebhookLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}
