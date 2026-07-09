<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery attempt of a webhook event to an endpoint.
 *
 * @property int|string                $id
 * @property int|string                $webhook_endpoint_id
 * @property string                    $event
 * @property array<string, mixed>|null $payload
 * @property int|null                  $status_code
 * @property bool                      $success
 * @property int                       $attempt
 * @property string|null               $response
 * @property Carbon|null               $created_at
 */
class WebhookLog extends Model
{
    protected $table = 'kinetix_webhook_logs';

    protected $guarded = [];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
        ];
    }
}
