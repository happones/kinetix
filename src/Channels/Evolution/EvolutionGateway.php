<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Evolution;

/**
 * The few Evolution API calls a WhatsApp line needs, behind one seam: the
 * default speaks through happones/laravel-evolution-client, and tests (or an
 * app with its own client) bind another.
 */
interface EvolutionGateway
{
    /**
     * Create the instance on the server.
     *
     * @return array{id: string|null, token: string|null}
     */
    public function create(string $instance): array;

    /**
     * Point the instance's webhook at Kinetix, sending `$headers` with every call.
     *
     * @param list<string>          $events
     * @param array<string, string> $headers
     */
    public function setWebhook(string $instance, string $url, array $events, array $headers): void;

    /**
     * Ask for a QR — or, with a phone number, a pairing code.
     *
     * @return array{qr: string|null, pairingCode: string|null}
     */
    public function connect(string $instance, ?string $phoneNumber = null): array;

    /** The connection state: `open`, `connecting` or `close`. */
    public function state(string $instance): string;

    /** The linked number's JID ("5215512345678@s.whatsapp.net"), once linked. */
    public function owner(string $instance): ?string;

    public function logout(string $instance): void;

    public function restart(string $instance): void;

    public function delete(string $instance): void;

    /**
     * Send a text — answering `$quotedId` when given — and return the
     * provider's id for it.
     */
    public function sendText(string $instance, string $to, string $text, ?string $quotedId = null): ?string;

    /**
     * Send a file (`$type` image, video, audio or document, as base64) and
     * return the provider's id for it.
     */
    public function sendMedia(string $instance, string $to, string $type, string $base64, string $mime, string $fileName, ?string $caption = null, ?string $quotedId = null): ?string;

    /**
     * The file of a message the line received, by the message's id.
     *
     * @return array{base64: string, mimetype: string|null, fileName: string|null}|null
     */
    public function mediaBase64(string $instance, string $messageId): ?array;
}
