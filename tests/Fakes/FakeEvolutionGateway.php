<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use RuntimeException;

/** An Evolution API server in memory: records every call, answers as configured. */
class FakeEvolutionGateway implements EvolutionGateway
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public string $state = 'connecting';

    public ?string $failOn = null;

    public function create(string $instance): array
    {
        return $this->record('create', func_get_args(), ['id' => 'evo-1', 'token' => 'instance-token']);
    }

    public function setWebhook(string $instance, string $url, array $events, array $headers): void
    {
        $this->record('setWebhook', func_get_args());
    }

    public function connect(string $instance, ?string $phoneNumber = null): array
    {
        return $this->record('connect', func_get_args(), ['qr' => 'iVBORw0KGgo=', 'pairingCode' => $phoneNumber ? 'ABCD1234' : null]);
    }

    public function state(string $instance): string
    {
        return $this->record('state', func_get_args(), $this->state);
    }

    public ?string $owner = '5215512345678@s.whatsapp.net';

    public function owner(string $instance): ?string
    {
        return $this->record('owner', func_get_args(), $this->owner);
    }

    public function logout(string $instance): void
    {
        $this->record('logout', func_get_args());
    }

    public function restart(string $instance): void
    {
        $this->record('restart', func_get_args());
    }

    public function delete(string $instance): void
    {
        $this->record('delete', func_get_args());
    }

    public function sendText(string $instance, string $to, string $text, ?string $quotedId = null): ?string
    {
        return $this->record('sendText', func_get_args(), 'OUT-'.count($this->calls));
    }

    public function sendMedia(string $instance, string $to, string $type, string $base64, string $mime, string $fileName, ?string $caption = null, ?string $quotedId = null): ?string
    {
        return $this->record('sendMedia', func_get_args(), 'OUT-'.count($this->calls));
    }

    public function mediaBase64(string $instance, string $messageId): ?array
    {
        return $this->record('mediaBase64', func_get_args(), ['base64' => base64_encode('fake-image-bytes'), 'mimetype' => 'image/jpeg', 'fileName' => null]);
    }

    /** @return list<array<int, mixed>> */
    public function callsTo(string $method): array
    {
        return array_values(array_map(static fn (array $call): array => $call[1], array_filter($this->calls, static fn (array $call): bool => $call[0] === $method)));
    }

    /**
     * @param  array<int, mixed> $arguments
     * @return mixed
     */
    private function record(string $method, array $arguments, mixed $result = null)
    {
        $this->calls[] = [$method, $arguments];

        if ($this->failOn === $method) {
            throw new RuntimeException('Evolution is down', 500);
        }

        return $result;
    }
}
