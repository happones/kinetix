<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

/**
 * Where linking stands: the QR to scan (a data URI) or the pairing code to
 * type on the phone, how long they last, or the line's status once linked.
 */
final class LinkState
{
    public function __construct(
        public readonly ChannelStatus $status,
        public readonly ?string $qr = null,
        public readonly ?string $pairingCode = null,
        public readonly ?int $expiresIn = null,
    ) {}

    /**
     * @return array{status: string, qr: string|null, pairingCode: string|null, expiresIn: int|null}
     */
    public function toArray(): array
    {
        return [
            'status'      => $this->status->value,
            'qr'          => $this->qr,
            'pairingCode' => $this->pairingCode,
            'expiresIn'   => $this->expiresIn,
        ];
    }
}
