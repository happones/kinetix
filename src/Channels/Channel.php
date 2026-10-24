<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A messaging line a team links — a WhatsApp number, a Telegram bot — through
 * one of the registered channel drivers. Its credentials are encrypted, and
 * `public_id` + `webhook_secret` authenticate the provider's webhook calls.
 *
 * @property int|string           $id
 * @property string               $public_id
 * @property int|string|null      $team_id
 * @property string               $provider
 * @property string               $name
 * @property ChannelStatus        $status
 * @property string|null          $handle
 * @property string|null          $external_id
 * @property array<string, mixed> $credentials
 * @property string               $webhook_secret
 * @property array<string, mixed> $meta
 * @property string|null          $last_error
 * @property Carbon|null          $connected_at
 * @property Carbon|null          $last_event_at
 * @property Carbon|null          $checked_at
 * @property Carbon|null          $created_at
 */
class Channel extends Model
{
    protected $table = 'kinetix_channels';

    protected $guarded = [];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected static function booted(): void
    {
        static::creating(function (Channel $channel): void {
            $channel->public_id      ??= (string) Str::ulid();
            $channel->webhook_secret ??= Str::random(48);
            $channel->status         ??= ChannelStatus::Disconnected;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'        => ChannelStatus::class,
            'credentials'   => 'encrypted:array',
            'meta'          => 'array',
            'connected_at'  => 'datetime',
            'last_event_at' => 'datetime',
            'checked_at'    => 'datetime',
        ];
    }

    /** A credential the driver stored at provisioning (a bot token, an instance token). */
    public function credential(string $key): mixed
    {
        return ($this->credentials ?? [])[$key] ?? null;
    }

    public function isConnected(): bool
    {
        return $this->status === ChannelStatus::Connected;
    }

    /** Whether the provider runs a bot of its own on this line ({@see HostsBots}). */
    public function hasExternalBot(): bool
    {
        return is_array($this->meta['bot'] ?? null);
    }

    /**
     * The lines a user sees and manages: `channels.visible`, a callable array
     * receiving the query and the user, narrows them — e.g. to leave each
     * member's personal line out of the team's manager, its plan count and
     * the inbox. The line manager, the inbox and the bot editor all use it.
     *
     * @param  Builder<Channel> $query
     * @return Builder<Channel>
     */
    public function scopeVisibleTo(Builder $query, ?Model $user): Builder
    {
        $callback = ConfigCallback::resolve(config('kinetix.channels.visible'));

        if ($callback !== null) {
            $callback($query, $user);
        }

        return $query;
    }
}
