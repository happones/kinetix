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
use Happones\Kinetix\Channels\Evolution\EvolutionBotGateway;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Channels\Evolution\WhatsAppBot;
use Happones\Kinetix\Channels\HostsBots;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Channels\LinkState;
use Happones\Kinetix\Channels\OutgoingMessage;
use Happones\Kinetix\Channels\ReportsHandle;
use Happones\Kinetix\Channels\SendsMessages;
use Happones\Kinetix\Channels\WebhookResult;
use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * WhatsApp lines through an Evolution API server: each line is an instance,
 * linked by scanning a QR from WhatsApp → Linked devices, or by typing a
 * pairing code on the phone. The server calls the line's webhook with the
 * secret in `X-Kinetix-Channel-Secret`.
 */
class WhatsAppDriver implements ChannelDriver, DownloadsMedia, HostsBots, ReportsHandle, SendsMessages
{
    public const SECRET_HEADER = 'X-Kinetix-Channel-Secret';

    /** @var list<string> */
    protected const EVENTS = ['QRCODE_UPDATED', 'CONNECTION_UPDATE', 'MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'MESSAGES_DELETE', 'SEND_MESSAGE'];

    public function __construct(protected EvolutionGateway $gateway) {}

    /**
     * The events the line's webhook subscribes to: Kinetix's own, plus
     * `providers.whatsapp.webhook_events`. Override it in a child driver to
     * add more — every call that points the webhook at Kinetix uses it.
     *
     * @return list<string>
     */
    protected function webhookEvents(): array
    {
        $extra = array_filter((array) config('kinetix.channels.providers.whatsapp.webhook_events', []), 'is_string');

        return array_values(array_unique([...static::EVENTS, ...$extra]));
    }

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
            $this->gateway->setWebhook($instance, $webhookUrl, $this->webhookEvents(), [self::SECRET_HEADER => $channel->webhook_secret]);
        } catch (Throwable $exception) {
            // No half-made line left on the server.
            rescue(fn () => $this->gateway->delete($instance), report: false);

            throw new ChannelException(__('kinetix::kinetix.channel_error_unreachable'), previous: $exception);
        }

        $channel->external_id = $instance;
        $channel->credentials = array_filter(['instance_id' => $created['id'], 'token' => $created['token']]);
        $channel->status      = ChannelStatus::Connecting;

        $bot = $this->configuredBot($channel);

        if ($bot !== null) {
            try {
                $this->attachBot($channel, $bot);
            } catch (Throwable $exception) {
                // A line that needs its bot isn't left without it; the team
                // only sees that the line couldn't be set up.
                report($exception);
                rescue(fn () => $this->gateway->delete($instance), report: false);

                throw new ChannelException(__('kinetix::kinetix.channel_error_unreachable'), previous: $exception);
            }
        }
    }

    public function attachBot(Channel $channel, WhatsAppBot $bot): void
    {
        $gateway  = $this->botGateway();
        $instance = $this->instance($channel);

        $this->detachBot($channel);

        $credentials = $bot->credentials !== null
            ? $gateway->createBotCredentials($instance, $bot->integration, $bot->credentials['name'], $bot->credentials['apiKey'])
            : null;

        if ($bot->settings !== []) {
            $gateway->botSettings($instance, $bot->integration, $bot->settings);
        }

        $id = $gateway->createBot($instance, $bot->integration, $bot->payload($credentials));

        $channel->meta = [
            ...($channel->meta ?? []),
            'bot' => array_filter(['integration' => $bot->integration, 'id' => $id, 'credentials' => $credentials, 'name' => $bot->name]),
        ];

        // What the bot sends reaches the inbox: a line set up before Kinetix
        // listened for its own sends starts listening now.
        if ($channel->exists) {
            $this->gateway->setWebhook($instance, app(ChannelManager::class)->webhookUrl($channel), $this->webhookEvents(), [self::SECRET_HEADER => $channel->webhook_secret]);
        }
    }

    public function detachBot(Channel $channel): void
    {
        $bot = $channel->meta['bot'] ?? null;

        if (! is_array($bot)) {
            return;
        }

        if ($channel->external_id !== null && is_string($bot['integration'] ?? null) && is_string($bot['id'] ?? null)) {
            try {
                $this->botGateway()->deleteBot($channel->external_id, $bot['integration'], $bot['id']);
            } catch (Throwable $exception) {
                // Gone from the server already: nothing left to take off.
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
            }
        }

        $meta = $channel->meta;
        unset($meta['bot']);
        $channel->meta = $meta;
    }

    /**
     * The bot a new line gets from `kinetix.channels.providers.whatsapp.bot`:
     * a definition, or a callable array receiving the line (its team, its
     * name) and returning one — or null for none.
     */
    protected function configuredBot(Channel $channel): ?WhatsAppBot
    {
        $setting  = config('kinetix.channels.providers.whatsapp.bot');
        $callback = ConfigCallback::resolve($setting);
        $bot      = $callback !== null ? $callback($channel) : $setting;

        return $bot instanceof WhatsAppBot || (is_array($bot) && $bot !== []) ? WhatsAppBot::from($bot) : null;
    }

    protected function botGateway(): EvolutionBotGateway
    {
        if (! $this->gateway instanceof EvolutionBotGateway) {
            throw new LogicException('The Evolution gateway in use can\'t run bots: implement '.EvolutionBotGateway::class.'.');
        }

        return $this->gateway;
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

        try {
            $this->call(fn () => $this->gateway->logout($instance));
        } catch (ChannelException $exception) {
            // Refused because it isn't linked anymore ("instance is not
            // connected"): unlinking it is already done.
            if (! $exception->refusedByProvider || $this->call(fn (): string => $this->gateway->state($instance)) === 'open') {
                throw $exception;
            }
        }
    }

    /**
     * Restart the session, and point the webhook at Kinetix again with
     * today's events — what brings a line added by an older version up to
     * date (delivery receipts).
     */
    public function restart(Channel $channel): void
    {
        $instance = $this->instance($channel);
        $this->call(fn () => $this->gateway->setWebhook($instance, app(ChannelManager::class)->webhookUrl($channel), $this->webhookEvents(), [self::SECRET_HEADER => $channel->webhook_secret]));
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
            // Buttons aren't reliable on WhatsApp outside its official API: options go as a numbered menu.
            return $this->call(fn (): ?string => $this->gateway->sendText($instance, $message->to, $message->textWithOptions(), $message->replyTo));
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
            // The line's own messages here were typed on its phone…
            'messages.upsert' => new WebhookResult(messages: $this->messages($data), sent: $this->messages($data, fromLine: true, fromPhone: true), deleted: $this->revoked($data)),
            'messages.delete' => new WebhookResult(deleted: $this->deletedIds($data)),
            // …these the server sent for it: Kinetix's sends, its bot's, the API's.
            'send.message'    => new WebhookResult(sent: $this->messages($data, fromLine: true)),
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
     * The messages of a `messages.upsert` or `send.message` call — one
     * message, or a list of them: what contacts wrote, or (`$fromLine`) what
     * the line itself sent, addressed to the contact it went to — typed on its
     * phone (`$fromPhone`) or sent by the server.
     *
     * @param  array<string, mixed> $data
     * @return list<InboundMessage>
     */
    protected function messages(array $data, bool $fromLine = false, bool $fromPhone = false): array
    {
        $items    = isset($data['key']) ? [$data] : (array) ($data['messages'] ?? []);
        $messages = [];

        foreach ($items as $item) {
            if (! is_array($item) || (data_get($item, 'key.fromMe') === true) !== $fromLine) {
                continue;
            }

            $jid = (string) data_get($item, 'key.remoteJid', '');

            // A contact hidden behind a LID: reply to the phone number the
            // server reports alongside it.
            if (str_ends_with($jid, '@lid')) {
                $jid = (string) (data_get($item, 'key.senderPn') ?? data_get($item, 'key.remoteJidAlt') ?? $jid);
            }

            if (! $this->isChat($jid)) {
                continue;
            }

            // A "deleted for everyone" notice, not a message ({@see revoked()}).
            if (data_get($item, 'message.protocolMessage') !== null) {
                continue;
            }

            $isGroup    = str_ends_with($jid, '@g.us');
            $content    = (array) ($item['message'] ?? []);
            $timestamp  = data_get($item, 'messageTimestamp');
            $type       = $this->type((string) ($item['messageType'] ?? array_key_first($content) ?? ''));
            $id         = is_scalar($key = data_get($item, 'key.id')) ? (string) $key : null;
            $media      = $this->media($content);
            $messages[] = new InboundMessage(
                from: $isGroup ? $jid : (string) preg_replace('/@.*$/', '', $jid),
                // On the line's own messages the name is the line's, not the contact's.
                fromName: ! $fromLine && is_string($name = $item['pushName'] ?? null) && $name !== '' ? $name : null,
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
                fromPhone: $fromLine && $fromPhone,
            );
        }

        return $messages;
    }

    /**
     * The messages a `messages.upsert` call says were deleted for everyone:
     * a `protocolMessage` of type REVOKE naming the message it takes back.
     *
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    protected function revoked(array $data): array
    {
        $items = isset($data['key']) ? [$data] : (array) ($data['messages'] ?? []);
        $ids   = [];

        foreach ($items as $item) {
            $protocol = data_get($item, 'message.protocolMessage');

            if (is_array($protocol) && in_array($protocol['type'] ?? null, ['REVOKE', 0], true) && is_scalar($id = data_get($protocol, 'key.id'))) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The ids of a `messages.delete` call: one message's key, or a list —
     * bare, or wrapped as `{"keys": [...]}` as some server builds send it.
     *
     * @param  array<int|string, mixed> $data
     * @return list<string>
     */
    protected function deletedIds(array $data): array
    {
        if (is_array($data['keys'] ?? null)) {
            $data = $data['keys'];
        }

        $ids = [];

        foreach (array_is_list($data) ? $data : [$data] as $item) {
            $id = data_get($item, 'id') ?? data_get($item, 'key.id') ?? data_get($item, 'keyId');

            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Whether a JID is a conversation the inbox takes. Statuses
     * (`status@broadcast`) never are; with `providers.whatsapp.direct_only`,
     * neither are groups, broadcast lists or channels (newsletters) — only
     * one-to-one chats.
     */
    protected function isChat(string $jid): bool
    {
        if ($jid === '' || $jid === 'status@broadcast') {
            return false;
        }

        if (! config('kinetix.channels.providers.whatsapp.direct_only', false)) {
            return true;
        }

        return ! str_ends_with($jid, '@g.us') && ! str_ends_with($jid, '@broadcast') && ! str_ends_with($jid, '@newsletter');
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
            $status = (int) $exception->getCode();

            if ($status === 404) {
                throw new ChannelException(__('kinetix::kinetix.channel_error_missing'), previous: $exception);
            }

            // The server answered, and said no: its reason, not "unreachable".
            if ($status >= 400 && $status < 500) {
                throw new ChannelException(
                    __('kinetix::kinetix.channel_error_whatsapp', ['reason' => $this->providerReason($exception)]),
                    previous: $exception,
                    refusedByProvider: true,
                );
            }

            throw $exception;
        }
    }

    /**
     * Why the server refused: Evolution puts it in the body's
     * `response.message` (a string or a list), under a generic `error`
     * ("Bad Request") the client keeps as the exception's message.
     */
    protected function providerReason(Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (method_exists($cause, 'getResponse') && is_object($response = $cause->getResponse()) && method_exists($response, 'getBody')) {
                $body    = json_decode((string) $response->getBody(), true);
                $message = is_array($body) ? (data_get($body, 'response.message') ?? data_get($body, 'message')) : null;
                $message = is_array($message) ? implode(' ', array_filter($message, 'is_string')) : $message;

                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }
        }

        return $exception->getMessage();
    }
}
