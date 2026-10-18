<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * Where a line stands with its provider: never linked or logged out
 * (`Disconnected`), waiting for a QR scan or a pairing code (`Connecting`),
 * live (`Connected`), or rejected by the provider — a revoked token, an
 * unreachable server (`Failed`, with the reason in `last_error`).
 */
enum ChannelStatus: string
{
    case Disconnected = 'disconnected';
    case Connecting   = 'connecting';
    case Connected    = 'connected';
    case Failed       = 'failed';

    public function label(): string
    {
        return __('kinetix::kinetix.channel_status_'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected    => 'success',
            self::Connecting   => 'warning',
            self::Failed       => 'danger',
            self::Disconnected => 'gray',
        };
    }
}
