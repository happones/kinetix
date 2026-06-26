<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
