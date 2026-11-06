<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * A messaging line a team links — a WhatsApp number, a Telegram bot — through
 * one of the registered channel drivers. Its credentials are encrypted, and
 * `public_id` + `webhook_secret` authenticate the provider's webhook calls:
 * the line is found by `public_id`, then the secret compared with
 * `hash_equals`. The secret is encrypted at rest too; one stored before
 * that reads as it is until `kinetix:webhooks:encrypt-secrets` encrypts it.
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
            'status'         => ChannelStatus::class,
            'credentials'    => 'encrypted:array',
            'webhook_secret' => EncryptedWithPlaintextFallback::class,
            'meta'           => 'array',
            'connected_at'   => 'datetime',
            'last_event_at'  => 'datetime',
            'checked_at'     => 'datetime',
        ];
    }

    /**
     * Lines whose secret failed to decrypt, already reported by this process.
     *
     * @var array<string, true>
     */
    private static array $undecryptableReported = [];

    /**
     * Whether `$given` is this line's webhook secret (constant-time). A
     * secret that can't be decrypted (the app key rotated without
     * `APP_PREVIOUS_KEYS`) matches nothing, so the provider's call is
     * refused with a 401 instead of failing with a 500 the provider would
     * keep retrying; it is reported once per line and process.
     */
    public function matchesWebhookSecret(mixed $given): bool
    {
        if (! is_string($given) || $given === '') {
            return false;
        }

        try {
            $secret = (string) $this->getAttribute('webhook_secret');
        } catch (Throwable $exception) {
            if (! $exception instanceof DecryptException) {
                throw $exception;
            }

            $key = (string) $this->getKey();

            if (! isset(self::$undecryptableReported[$key])) {
                self::$undecryptableReported[$key] = true;
                report($exception);
            }

            return false;
        }

        return $secret !== '' && hash_equals($secret, $given);
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
