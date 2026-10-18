<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Drivers;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelDriver;
use Happones\Kinetix\Channels\ChannelException;
use Happones\Kinetix\Channels\ChannelManager;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\DownloadedMedia;
use Happones\Kinetix\Channels\DownloadsMedia;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Channels\LinkState;
use Happones\Kinetix\Channels\OutgoingMessage;
use Happones\Kinetix\Channels\ReportsHandle;
use Happones\Kinetix\Channels\SendsMessages;
use Happones\Kinetix\Channels\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * WhatsApp lines through an Evolution API server: each line is an instance,
 * linked by scanning a QR from WhatsApp → Linked devices, or by typing a
 * pairing code on the phone. The server calls the line's webhook with the
 * secret in `X-Kinetix-Channel-Secret`.
 */
class WhatsAppDriver implements ChannelDriver, DownloadsMedia, ReportsHandle, SendsMessages
{
    public const SECRET_HEADER = 'X-Kinetix-Channel-Secret';

    /** @var list<string> */
    protected const EVENTS = ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT', 'MESSAGES_UPDATE'];

    public function __construct(protected EvolutionGateway $gateway) {}

    public function linkMethods(): array
    {
        return ['qr', 'pairing_code'];
    }

    public function rules(): array
    {
        return [];
    }

    public function provision(Channel $channel, array $input, string $webhookUrl): void
    {
        $instance = $this->prefix().strtolower($channel->public_id);

        try {
            $created = $this->gateway->create($instance);
        } catch (Throwable $exception) {
            throw new ChannelException(__('kinetix::kinetix.channel_error_unreachable'), previous: $exception);
        }

        try {
            $this->gateway->setWebhook($instance, $webhookUrl, self::EVENTS, [self::SECRET_HEADER => $channel->webhook_secret]);
        } catch (Throwable $exception) {
            // No half-made line left on the server.
            rescue(fn () => $this->gateway->delete($instance), report: false);

            throw new ChannelException(__('kinetix::kinetix.channel_error_unreachable'), previous: $exception);
        }

        $channel->external_id = $instance;
        $channel->credentials = array_filter(['instance_id' => $created['id'], 'token' => $created['token']]);
        $channel->status      = ChannelStatus::Connecting;
    }

    public function link(Channel $channel, ?string $phoneNumber = null): LinkState
    {
        $instance = $this->instance($channel);

        if ($this->call(fn (): string => $this->gateway->state($instance)) === 'open') {
            return new LinkState(ChannelStatus::Connected);
        }

        $link = $this->call(fn (): array => $this->gateway->connect($instance, $phoneNumber));

        return new LinkState(
            ChannelStatus::Connecting,
            qr: $phoneNumber === null ? $this->dataUri($link['qr']) : null,
            pairingCode: $phoneNumber !== null ? $link['pairingCode'] : null,
            expiresIn: (int) config('kinetix.channels.providers.whatsapp.qr_ttl', 30),
        );
    }

    public function status(Channel $channel): ChannelStatus
    {
        $instance = $this->instance($channel);

        return $this->fromState($this->call(fn (): string => $this->gateway->state($instance)));
    }

    public function handle(Channel $channel): ?string
    {
        $instance = $this->instance($channel);

        return $this->handleFrom($this->call(fn (): ?string => $this->gateway->owner($instance)));
    }

    public function disconnect(Channel $channel): void
    {
        $instance = $this->instance($channel);
        $this->call(fn () => $this->gateway->logout($instance));
    }

    /**
     * Restart the session, and point the webhook at Kinetix again with
     * today's events — what brings a line added by an older version up to
     * date (delivery receipts).
     */
    public function restart(Channel $channel): void
    {
        $instance = $this->instance($channel);
        $this->call(fn () => $this->gateway->setWebhook($instance, app(ChannelManager::class)->webhookUrl($channel), self::EVENTS, [self::SECRET_HEADER => $channel->webhook_secret]));
        $this->call(fn () => $this->gateway->restart($instance));
    }

    public function remove(Channel $channel): void
    {
        if ($channel->external_id !== null) {
            $this->gateway->delete($channel->external_id);
        }
    }

    public function sendText(Channel $channel, string $to, string $text): void
    {
        $this->send($channel, new OutgoingMessage($to, $text));
    }

    public function send(Channel $channel, OutgoingMessage $message): ?string
    {
        $instance = $this->instance($channel);

        if (! $message->hasMedia()) {
            return $this->call(fn (): ?string => $this->gateway->sendText($instance, $message->to, (string) $message->text, $message->replyTo));
        }

        return $this->call(fn (): ?string => $this->gateway->sendMedia(
            $instance,
            $message->to,
            $message->mediaType ?? 'document',
            base64_encode((string) $message->mediaContents),
            $message->mediaMime ?? 'application/octet-stream',
            $message->mediaName ?? 'file',
            $message->text,
            $message->replyTo,
        ));
    }

    public function downloadMedia(Channel $channel, string $mediaRef): ?DownloadedMedia
    {
        $instance = $this->instance($channel);
        $media    = $this->call(fn (): ?array => $this->gateway->mediaBase64($instance, $mediaRef));
        $contents = $media !== null ? base64_decode($media['base64'], true) : false;

        return $contents === false ? null : new DownloadedMedia($contents, $media['mimetype'] ?? null, $media['fileName'] ?? null);
    }

    public function verifyWebhook(Channel $channel, Request $request): bool
    {
        $secret = $request->header(self::SECRET_HEADER);

        return is_string($secret) && hash_equals($channel->webhook_secret, $secret);
    }

    public function parseWebhook(Channel $channel, Request $request): WebhookResult
    {
        $event = strtolower(str_replace('_', '.', (string) $request->input('event')));
        $data  = (array) $request->input('data', []);

        return match ($event) {
            'connection.update' => new WebhookResult(
                status: $this->fromState((string) ($data['state'] ?? 'close')),
                handle: $this->handleFrom($data['wuid'] ?? null),
            ),
            'qrcode.updated' => new WebhookResult(
                status: ChannelStatus::Connecting,
                qr: $this->dataUri(data_get($data, 'qrcode.base64')),
            ),
            'messages.upsert' => new WebhookResult(messages: $this->messages($data)),
            'messages.update' => new WebhookResult(statuses: $this->statuses((array) $request->input('data', []))),
            default           => WebhookResult::none(),
        };
    }

    protected function fromState(string $state): ChannelStatus
    {
        return match ($state) {
            'open'       => ChannelStatus::Connected,
            'connecting' => ChannelStatus::Connecting,
            default      => ChannelStatus::Disconnected,
        };
    }

    /**
     * The inbound messages of a `messages.upsert` call — one message, or a
     * list of them — leaving out the line's own.
     *
     * @param  array<string, mixed> $data
     * @return list<InboundMessage>
     */
    protected function messages(array $data): array
    {
        $items    = isset($data['key']) ? [$data] : (array) ($data['messages'] ?? []);
        $messages = [];

        foreach ($items as $item) {
            if (! is_array($item) || data_get($item, 'key.fromMe') === true) {
                continue;
            }

            $jid = (string) data_get($item, 'key.remoteJid', '');

            // A contact hidden behind a LID: reply to the phone number the
            // server reports alongside it.
            if (str_ends_with($jid, '@lid')) {
                $jid = (string) (data_get($item, 'key.senderPn') ?? data_get($item, 'key.remoteJidAlt') ?? $jid);
            }

            $isGroup    = str_ends_with($jid, '@g.us');
            $content    = (array) ($item['message'] ?? []);
            $timestamp  = data_get($item, 'messageTimestamp');
            $type       = $this->type((string) ($item['messageType'] ?? array_key_first($content) ?? ''));
            $id         = is_scalar($key = data_get($item, 'key.id')) ? (string) $key : null;
            $media      = $this->media($content);
            $messages[] = new InboundMessage(
                from: $isGroup ? $jid : (string) preg_replace('/@.*$/', '', $jid),
                fromName: is_string($name = $item['pushName'] ?? null) && $name !== '' ? $name : null,
                text: $this->text($content),
                type: $type,
                externalId: $id,
                isGroup: $isGroup,
                receivedAt: is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : now(),
                raw: $item,
                replyTo: $this->quoted($content),
                // The server fetches a message's file by the message's own id.
                mediaRef: $media !== null ? $id : null,
                mediaMime: $media['mimetype'] ?? null,
                mediaName: $media['fileName'] ?? null,
            );
        }

        return $messages;
    }

    /**
     * The delivery receipts of a `messages.update` call — one, or a list.
     *
     * @param  array<int|string, mixed> $data
     * @return array<string, string>
     */
    protected function statuses(array $data): array
    {
        $items    = array_is_list($data) ? $data : [$data];
        $statuses = [];

        foreach ($items as $item) {
            $id     = data_get($item, 'keyId') ?? data_get($item, 'key.id');
            $status = match (data_get($item, 'status') ?? data_get($item, 'update.status')) {
                'SERVER_ACK', 2        => 'sent',
                'DELIVERY_ACK', 3      => 'delivered',
                'READ', 'PLAYED', 4, 5 => 'read',
                'ERROR', 0             => 'failed',
                default                => null,
            };

            if (is_scalar($id) && $status !== null) {
                $statuses[(string) $id] = $status;
            }
        }

        return $statuses;
    }

    /**
     * The file part of a message (its MIME type and name), when it has one.
     *
     * @param  array<string, mixed>                                     $content
     * @return array{mimetype: string|null, fileName: string|null}|null
     */
    protected function media(array $content): ?array
    {
        foreach (['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage', 'documentWithCaptionMessage.message.documentMessage'] as $path) {
            $part = data_get($content, $path);

            if (is_array($part)) {
                return [
                    'mimetype' => is_string($part['mimetype'] ?? null) ? $part['mimetype'] : null,
                    'fileName' => is_string($part['fileName'] ?? null) ? $part['fileName'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * The id of the message this one answers, from its context.
     *
     * @param array<string, mixed> $content
     */
    protected function quoted(array $content): ?string
    {
        foreach ($content as $part) {
            $id = is_array($part) ? data_get($part, 'contextInfo.stanzaId') : null;

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function text(array $content): ?string
    {
        foreach (['conversation', 'extendedTextMessage.text', 'imageMessage.caption', 'videoMessage.caption', 'documentMessage.caption', 'documentWithCaptionMessage.message.documentMessage.caption'] as $path) {
            $text = data_get($content, $path);

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    protected function type(string $messageType): string
    {
        return match ($messageType) {
            'conversation', 'extendedTextMessage'           => 'text',
            'imageMessage'                                  => 'image',
            'videoMessage'                                  => 'video',
            'audioMessage'                                  => 'audio',
            'documentMessage', 'documentWithCaptionMessage' => 'document',
            'stickerMessage'                                => 'sticker',
            'locationMessage'                               => 'location',
            'contactMessage'                                => 'contact',
            default                                         => 'other',
        };
    }

    /** "5215512345678@s.whatsapp.net" (or ":12@…" for a device) → "+5215512345678". */
    protected function handleFrom(mixed $jid): ?string
    {
        if (! is_string($jid) || $jid === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) preg_replace('/[:@].*$/', '', $jid));

        return $digits !== '' ? '+'.$digits : null;
    }

    protected function dataUri(?string $qr): ?string
    {
        if ($qr === null || $qr === '') {
            return null;
        }

        return str_starts_with($qr, 'data:') ? $qr : 'data:image/png;base64,'.$qr;
    }

    protected function instance(Channel $channel): string
    {
        if ($channel->external_id === null) {
            throw new ChannelException(__('kinetix::kinetix.channel_error_not_provisioned'));
        }

        return $channel->external_id;
    }

    protected function prefix(): string
    {
        return (string) config('kinetix.channels.providers.whatsapp.instance_prefix', 'kinetix_');
    }

    /**
     * Run a server call, turning "this instance is gone" into a failure the
     * line can show; anything else (the server down) propagates.
     *
     * @template T
     *
     * @param  callable(): T $call
     * @return T
     */
    protected function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $exception) {
            if ($exception->getCode() === 404) {
                throw new ChannelException(__('kinetix::kinetix.channel_error_missing'), previous: $exception);
            }

            throw $exception;
        }
    }
}
