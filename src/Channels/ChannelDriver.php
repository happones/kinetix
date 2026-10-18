<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Illuminate\Http\Request;

/**
 * A messaging provider behind Kinetix lines. Kinetix owns the line record,
 * its team scoping, its webhook URL and its UI; a driver only speaks to its
 * provider. Register one with `KinetixChannels::extend('sms', SmsDriver::class)`.
 */
interface ChannelDriver
{
    /**
     * How a line is linked: `qr`, `pairing_code` (a code typed on the phone)
     * and/or `token`.
     *
     * @return list<string>
     */
    public function linkMethods(): array;

    /**
     * Validation rules for the provider's own fields when a line is created
     * (a bot token, …), merged with Kinetix's own (`name`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Set the line up with the provider — create the remote instance, check
     * a token, register the webhook at `$webhookUrl` — and fill what it
     * learns (credentials, handle, external id, status) on the model; Kinetix
     * saves it. Throw {@see ChannelException} to refuse.
     *
     * @param array<string, mixed> $input the validated provider fields
     */
    public function provision(Channel $channel, array $input, string $webhookUrl): void;

    /** Start or resume linking: a fresh QR, or a pairing code for `$phoneNumber`. */
    public function link(Channel $channel, ?string $phoneNumber = null): LinkState;

    /** The line's status as the provider sees it now. */
    public function status(Channel $channel): ChannelStatus;

    /** Unlink the device or bot, keeping the line to link again. */
    public function disconnect(Channel $channel): void;

    /** Restart the provider's session for the line (re-register a bot's webhook). */
    public function restart(Channel $channel): void;

    /** Tear the line down at the provider before Kinetix deletes it. */
    public function remove(Channel $channel): void;

    /** Send a text to a recipient: a phone number, a chat id. */
    public function sendText(Channel $channel, string $to, string $text): void;

    /** Whether a webhook call comes from the provider (its secret header). */
    public function verifyWebhook(Channel $channel, Request $request): bool;

    /** What a webhook call says: a new status, a new QR, inbound messages. */
    public function parseWebhook(Channel $channel, Request $request): WebhookResult;
}
