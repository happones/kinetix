<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Drivers;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelDriver;
use Happones\Kinetix\Channels\ChannelException;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Channels\LinkState;
use Happones\Kinetix\Channels\ReportsHandle;
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
class WhatsAppDriver implements ChannelDriver, ReportsHandle
{
    public const SECRET_HEADER = 'X-Kinetix-Channel-Secret';

    /** @var list<string> */
    protected const EVENTS = ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT'];

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

    public function restart(Channel $channel): void
    {
        $instance = $this->instance($channel);
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
        $instance = $this->instance($channel);
        $this->call(fn () => $this->gateway->sendText($instance, $to, $text));
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
            $messages[] = new InboundMessage(
                from: $isGroup ? $jid : (string) preg_replace('/@.*$/', '', $jid),
                fromName: is_string($name = $item['pushName'] ?? null) && $name !== '' ? $name : null,
                text: $this->text($content),
                type: $this->type((string) ($item['messageType'] ?? array_key_first($content) ?? '')),
                externalId: is_scalar($id = data_get($item, 'key.id')) ? (string) $id : null,
                isGroup: $isGroup,
                receivedAt: is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : now(),
                raw: $item,
            );
        }

        return $messages;
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
