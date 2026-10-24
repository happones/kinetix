<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Evolution;

use Happones\LaravelEvolutionClient\EvolutionApiClient;
use Happones\LaravelEvolutionClient\Services\EvolutionService;
use RuntimeException;

/**
 * {@see EvolutionGateway} over happones/laravel-evolution-client. Each call
 * gets a client bound to its instance, so nothing leaks between lines in a
 * queue worker or under Octane. The server and key come from
 * `kinetix.channels.providers.whatsapp` (falling back to `evolution.*`).
 */
class EvolutionClientGateway implements EvolutionBotGateway, EvolutionGateway
{
    public function create(string $instance): array
    {
        $response = $this->client($instance)->instance->createInstance($instance, (string) $this->setting('integration', 'WHATSAPP-BAILEYS'), qrcode: true);

        $token = data_get($response, 'hash.apikey') ?? data_get($response, 'hash') ?? data_get($response, 'instance.token');

        return [
            'id'    => is_scalar($id = data_get($response, 'instance.instanceId')) ? (string) $id : null,
            'token' => is_string($token) ? $token : null,
        ];
    }

    public function setWebhook(string $instance, string $url, array $events, array $headers): void
    {
        $this->client($instance)->instance->setWebhook($url, $events, true, $headers);
    }

    public function connect(string $instance, ?string $phoneNumber = null): array
    {
        $response = $this->client($instance)->instance->connect($phoneNumber);

        return [
            'qr'          => $this->filled(data_get($response, 'base64') ?? data_get($response, 'qrcode.base64')),
            'pairingCode' => $this->filled(data_get($response, 'pairingCode') ?? data_get($response, 'qrcode.pairingCode')),
        ];
    }

    public function state(string $instance): string
    {
        $response = $this->client($instance)->instance->connectionState();
        $state    = data_get($response, 'instance.state') ?? data_get($response, 'state');

        return is_string($state) ? $state : 'close';
    }

    public function owner(string $instance): ?string
    {
        $service   = $this->service();
        $instances = $service->get('/instance/fetchInstances', ['instanceName' => $instance]);

        foreach ($instances as $item) {
            $name = data_get($item, 'name') ?? data_get($item, 'instance.instanceName');

            if ($name === $instance) {
                return $this->filled(data_get($item, 'ownerJid') ?? data_get($item, 'instance.owner'));
            }
        }

        return null;
    }

    public function logout(string $instance): void
    {
        $this->client($instance)->instance->disconnect();
    }

    public function restart(string $instance): void
    {
        $this->client($instance)->instance->restart();
    }

    public function delete(string $instance): void
    {
        $this->client($instance)->instance->delete();
    }

    public function sendText(string $instance, string $to, string $text, ?string $quotedId = null): ?string
    {
        $response = $this->service()->post("/message/sendText/{$instance}", array_filter([
            'number' => $to,
            'text'   => $text,
            'delay'  => $this->typingDelay($text),
            'quoted' => $quotedId !== null ? ['key' => ['id' => $quotedId]] : null,
        ]));

        return $this->filled(data_get($response, 'key.id'));
    }

    public function sendMedia(string $instance, string $to, string $type, string $base64, string $mime, string $fileName, ?string $caption = null, ?string $quotedId = null): ?string
    {
        $quoted = $quotedId !== null ? ['key' => ['id' => $quotedId]] : null;

        // A voice note goes through its own endpoint, played as one.
        $response = $type === 'audio'
            ? $this->service()->post("/message/sendWhatsAppAudio/{$instance}", array_filter(['number' => $to, 'audio' => $base64, 'delay' => $this->typingDelay(null), 'quoted' => $quoted]))
            : $this->service()->post("/message/sendMedia/{$instance}", array_filter([
                'number'    => $to,
                'delay'     => $this->typingDelay($caption),
                'mediatype' => $type,
                'mimetype'  => $mime,
                'caption'   => $caption,
                'media'     => $base64,
                'fileName'  => $fileName,
                'quoted'    => $quoted,
            ]));

        return $this->filled(data_get($response, 'key.id'));
    }

    public function mediaBase64(string $instance, string $messageId): ?array
    {
        $response = $this->client($instance)->chat->getBase64FromMediaMessage($messageId);
        $base64   = $this->filled(data_get($response, 'base64'));

        return $base64 === null ? null : [
            'base64'   => $base64,
            'mimetype' => $this->filled(data_get($response, 'mimetype')),
            'fileName' => $this->filled(data_get($response, 'fileName')),
        ];
    }

    public function createBot(string $instance, string $integration, array $bot): string
    {
        $response = $this->service()->post("/{$integration}/create/{$instance}", $bot);

        return $this->id($response, $integration);
    }

    public function createBotCredentials(string $instance, string $integration, string $name, string $apiKey): string
    {
        $response = $this->service()->post("/{$integration}/creds/{$instance}", ['name' => $name, 'apiKey' => $apiKey]);

        return $this->id($response, $integration);
    }

    public function botSettings(string $instance, string $integration, array $settings): void
    {
        $this->service()->post("/{$integration}/settings/{$instance}", $settings);
    }

    public function deleteBot(string $instance, string $integration, string $botId): void
    {
        $this->service()->delete("/{$integration}/delete/{$botId}/{$instance}");
    }

    /** @param array<array-key, mixed> $response */
    protected function id(array $response, string $integration): string
    {
        $id = data_get($response, 'id') ?? data_get($response, "{$integration}.id");

        if (! is_scalar($id) || (string) $id === '') {
            throw new RuntimeException("Evolution created the {$integration} item without an id.");
        }

        return (string) $id;
    }

    /**
     * How long the contact sees "typing…" before a message lands
     * (`providers.whatsapp.typing`): a base plus some per character, capped —
     * so a number answering at once doesn't read as a bot. Evolution shows
     * the presence for the `delay` it's given. Null when off.
     */
    protected function typingDelay(?string $text): ?int
    {
        $typing = (array) $this->setting('typing', []);

        if (! ($typing['enabled'] ?? false)) {
            return null;
        }

        $delay = (int) ($typing['min_ms'] ?? 800) + mb_strlen((string) $text) * (int) ($typing['ms_per_char'] ?? 40);

        return min($delay, (int) ($typing['max_ms'] ?? 6000));
    }

    protected function client(string $instance): EvolutionApiClient
    {
        return new EvolutionApiClient($this->service(), $instance);
    }

    protected function service(): EvolutionService
    {
        return new EvolutionService(
            $this->baseUrl(),
            (string) ($this->setting('api_key') ?? config('evolution.api_key', '')),
            (int) ($this->setting('timeout') ?? config('evolution.timeout', 30)),
        );
    }

    /** The server's URL, with a scheme even when one was left off. */
    protected function baseUrl(): string
    {
        $url = (string) ($this->setting('base_url') ?? config('evolution.base_url', 'http://localhost:8080'));

        return preg_match('#^https?://#i', $url) === 1 ? rtrim($url, '/') : 'https://'.rtrim($url, '/');
    }

    protected function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        $value = config("kinetix.channels.providers.whatsapp.{$key}");

        return $value === null || $value === '' ? $default : $value;
    }
}
