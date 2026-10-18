<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Drivers;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelDriver;
use Happones\Kinetix\Channels\ChannelException;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Channels\LinkState;
use Happones\Kinetix\Channels\WebhookResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Telegram lines as bots: a team creates one with @BotFather and pastes its
 * token; Kinetix checks it, names the line after the bot and registers the
 * webhook with a secret token Telegram sends back in
 * `X-Telegram-Bot-Api-Secret-Token`. Speaks the Bot API over HTTP — to
 * api.telegram.org or a self-hosted Bot API server (`api_url`).
 */
class TelegramDriver implements ChannelDriver
{
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /** @var list<string> */
    protected const UPDATES = ['message', 'edited_message', 'callback_query', 'business_connection', 'business_message'];

    public function linkMethods(): array
    {
        return ['token'];
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:255', 'regex:/^\d+:[\w-]+$/']];
    }

    public function provision(Channel $channel, array $input, string $webhookUrl): void
    {
        $token = (string) $input['token'];
        $bot   = $this->call($token, 'getMe', field: 'token');

        $this->registerWebhook($token, $webhookUrl, $channel->webhook_secret);

        $channel->credentials = ['token' => $token];
        $channel->external_id = (string) ($bot['id'] ?? '');
        $channel->handle      = isset($bot['username']) ? '@'.$bot['username'] : null;
        $channel->meta        = ['bot_name' => $bot['first_name'] ?? null, 'webhook_url' => $webhookUrl];
        $channel->status      = ChannelStatus::Connected;
    }

    /** A bot has nothing to scan: linking registers its webhook again. */
    public function link(Channel $channel, ?string $phoneNumber = null): LinkState
    {
        $this->restart($channel);

        return new LinkState(ChannelStatus::Connected);
    }

    public function status(Channel $channel): ChannelStatus
    {
        $info = $this->call($this->token($channel), 'getWebhookInfo');

        return ($info['url'] ?? '') === ($channel->meta['webhook_url'] ?? null) && ($info['url'] ?? '') !== ''
            ? ChannelStatus::Connected
            : ChannelStatus::Disconnected;
    }

    public function disconnect(Channel $channel): void
    {
        $this->call($this->token($channel), 'deleteWebhook');
    }

    public function restart(Channel $channel): void
    {
        $this->registerWebhook($this->token($channel), (string) ($channel->meta['webhook_url'] ?? ''), $channel->webhook_secret);
    }

    public function remove(Channel $channel): void
    {
        $this->call($this->token($channel), 'deleteWebhook');
    }

    public function sendText(Channel $channel, string $to, string $text): void
    {
        $this->call($this->token($channel), 'sendMessage', ['chat_id' => $to, 'text' => $text]);
    }

    public function verifyWebhook(Channel $channel, Request $request): bool
    {
        $secret = $request->header(self::SECRET_HEADER);

        return is_string($secret) && hash_equals($channel->webhook_secret, $secret);
    }

    public function parseWebhook(Channel $channel, Request $request): WebhookResult
    {
        $message = $request->input('message') ?? $request->input('business_message');

        if (! is_array($message)) {
            return WebhookResult::none();
        }

        $chat = (array) ($message['chat'] ?? []);
        $from = (array) ($message['from'] ?? []);
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? null);

        return new WebhookResult(messages: [new InboundMessage(
            from: (string) ($chat['id'] ?? ''),
            fromName: is_string($name) && $name !== '' ? $name : null,
            text: $message['text'] ?? $message['caption'] ?? null,
            type: $this->type($message),
            externalId: isset($message['message_id']) ? (string) $message['message_id'] : null,
            isGroup: ! in_array($chat['type'] ?? 'private', ['private'], true),
            receivedAt: isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date']) : now(),
            raw: $request->all(),
        )]);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function type(array $message): string
    {
        foreach (['text' => 'text', 'photo' => 'image', 'video' => 'video', 'voice' => 'audio', 'audio' => 'audio', 'document' => 'document', 'sticker' => 'sticker', 'location' => 'location', 'contact' => 'contact'] as $key => $type) {
            if (isset($message[$key])) {
                return $type;
            }
        }

        return 'other';
    }

    protected function registerWebhook(string $token, string $url, string $secret): void
    {
        $this->call($token, 'setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => self::UPDATES,
        ]);
    }

    protected function token(Channel $channel): string
    {
        $token = $channel->credential('token');

        if (! is_string($token) || $token === '') {
            throw new ChannelException(__('kinetix::kinetix.channel_error_not_provisioned'));
        }

        return $token;
    }

    /**
     * Call a Bot API method and return its `result`. A rejected token is a
     * failure the line shows (under `$field` while it's being created).
     *
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function call(string $token, string $method, array $parameters = [], ?string $field = null): array
    {
        $url = rtrim((string) config('kinetix.channels.providers.telegram.api_url', 'https://api.telegram.org'), '/')."/bot{$token}/{$method}";

        try {
            $response = Http::timeout((int) config('kinetix.channels.providers.telegram.timeout', 10))->acceptJson()->post($url, $parameters);
        } catch (ConnectionException $exception) {
            throw new ChannelException(__('kinetix::kinetix.channel_error_unreachable'), previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 404) {
            $message = __('kinetix::kinetix.channel_error_telegram_token');

            throw $field !== null ? ChannelException::forField($field, $message) : new ChannelException($message);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new ChannelException($this->description($response));
        }

        $result = $response->json('result');

        return is_array($result) ? $result : ['value' => $result];
    }

    protected function description(Response $response): string
    {
        $description = $response->json('description');

        return is_string($description) && $description !== ''
            ? __('kinetix::kinetix.channel_error_telegram', ['reason' => $description])
            : __('kinetix::kinetix.channel_error_unreachable');
    }
}
