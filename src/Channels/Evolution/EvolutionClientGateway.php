<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Evolution;

use Happones\LaravelEvolutionClient\EvolutionApiClient;
use Happones\LaravelEvolutionClient\Services\EvolutionService;

/**
 * {@see EvolutionGateway} over happones/laravel-evolution-client. Each call
 * gets a client bound to its instance, so nothing leaks between lines in a
 * queue worker or under Octane. The server and key come from
 * `kinetix.channels.providers.whatsapp` (falling back to `evolution.*`).
 */
class EvolutionClientGateway implements EvolutionGateway
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

    public function sendText(string $instance, string $to, string $text): void
    {
        $this->client($instance)->message->sendText($to, $text);
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
