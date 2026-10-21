<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Evolution;

use InvalidArgumentException;

/**
 * A bot the Evolution API server runs on a WhatsApp line by itself — one of
 * its integrations (`evolutionBot`, `typebot`, `openai`, `dify`, `flowise`,
 * `n8n`, `evoai`) — for apps whose bot lives behind the scenes instead of in
 * Kinetix Flows. The team only links the number; the bot answers.
 *
 *     WhatsAppBot::evolutionBot('https://bots.example.com/whatsapp', apiKey: $key);
 *     WhatsAppBot::make('typebot', ['url' => 'https://typebot.io', 'typebot' => 'sales']);
 *
 * It answers every message unless told otherwise (`triggerType`), and steps
 * back in a chat once someone on the team writes in it (`stopBotFromMe`).
 */
final class WhatsAppBot
{
    /**
     * @param array<string, mixed>                     $bot         The integration's create payload.
     * @param array<string, mixed>                     $settings    The integration's defaults for the instance.
     * @param array{name: string, apiKey: string}|null $credentials Stored first, then referenced by the bot (OpenAI).
     */
    public function __construct(
        public readonly string $integration,
        public readonly array $bot,
        public readonly array $settings = [],
        public readonly ?array $credentials = null,
        /** How its messages are signed in the inbox; null = "Bot". */
        public readonly ?string $name = null,
    ) {
        // It's a segment of the server's URL.
        if (preg_match('/^[A-Za-z0-9]+$/', $integration) !== 1) {
            throw new InvalidArgumentException("Unknown Evolution integration [{$integration}].");
        }
    }

    /**
     * Any integration, with its payload as the Evolution API documents it.
     *
     * @param array<string, mixed> $bot
     */
    public static function make(string $integration, array $bot): self
    {
        return new self($integration, $bot);
    }

    /**
     * Evolution's own bot: it posts each message to your endpoint and sends
     * what it answers.
     *
     * @param array<string, mixed> $options
     */
    public static function evolutionBot(string $apiUrl, ?string $apiKey = null, array $options = []): self
    {
        return new self('evolutionBot', self::filled([...$options, 'apiUrl' => $apiUrl, 'apiKey' => $apiKey]));
    }

    /** @param array<string, mixed> $options */
    public static function typebot(string $url, string $typebot, array $options = []): self
    {
        return new self('typebot', [...$options, 'url' => $url, 'typebot' => $typebot]);
    }

    /**
     * An OpenAI bot: the key is stored on the server as credentials first.
     * `chatCompletion` takes a `model` (and `systemMessages`); `assistant` an
     * `assistantId`.
     *
     * @param array<string, mixed> $options
     */
    public static function openai(string $apiKey, string $botType = 'chatCompletion', array $options = []): self
    {
        $bot = [...$options, 'botType' => $botType];

        if ($botType === 'chatCompletion') {
            $bot['model'] ??= 'gpt-4o-mini';
        }

        return new self('openai', $bot, credentials: ['name' => 'kinetix', 'apiKey' => $apiKey]);
    }

    /**
     * @param string               $botType `chatBot`, `textGenerator`, `agent` or `workflow`.
     * @param array<string, mixed> $options
     */
    public static function dify(string $apiUrl, string $apiKey, string $botType = 'chatBot', array $options = []): self
    {
        return new self('dify', [...$options, 'botType' => $botType, 'apiUrl' => $apiUrl, 'apiKey' => $apiKey]);
    }

    /** @param array<string, mixed> $options */
    public static function flowise(string $apiUrl, ?string $apiKey = null, array $options = []): self
    {
        return new self('flowise', self::filled([...$options, 'apiUrl' => $apiUrl, 'apiKey' => $apiKey]));
    }

    /** @param array<string, mixed> $options */
    public static function n8n(string $webhookUrl, ?string $user = null, ?string $password = null, array $options = []): self
    {
        return new self('n8n', self::filled([...$options, 'webhookUrl' => $webhookUrl, 'basicAuthUser' => $user, 'basicAuthPass' => $password]));
    }

    /** @param array<string, mixed> $options */
    public static function evoai(string $agentUrl, ?string $apiKey = null, array $options = []): self
    {
        return new self('evoai', self::filled([...$options, 'agentUrl' => $agentUrl, 'apiKey' => $apiKey]));
    }

    /**
     * From config: `['integration' => 'typebot', 'bot' => [...], 'settings' => [...]]`
     * (`credentials` too, for OpenAI; `name` for how it signs in the inbox).
     *
     * @param self|array<string, mixed> $definition
     */
    public static function from(self|array $definition): self
    {
        if ($definition instanceof self) {
            return $definition;
        }

        $credentials = $definition['credentials'] ?? null;

        return new self(
            (string) ($definition['integration'] ?? ''),
            (array) ($definition['bot'] ?? []),
            (array) ($definition['settings'] ?? []),
            is_array($credentials) ? ['name' => (string) ($credentials['name'] ?? 'kinetix'), 'apiKey' => (string) ($credentials['apiKey'] ?? '')] : null,
            is_string($definition['name'] ?? null) ? $definition['name'] : null,
        );
    }

    /**
     * The integration's defaults for the instance: a fallback bot, how long a
     * session lasts, chats to ignore…
     *
     * @param array<string, mixed> $settings
     */
    public function withSettings(array $settings): self
    {
        return new self($this->integration, $this->bot, $settings, $this->credentials, $this->name);
    }

    /** The name its messages carry in the inbox ("Asistente"); the integration never shows. */
    public function named(string $name): self
    {
        return new self($this->integration, $this->bot, $this->settings, $this->credentials, $name);
    }

    /**
     * What creates the bot: on, answering every message, stepping back once
     * someone on the team writes — unless the bot says otherwise.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $credentialsId = null): array
    {
        return [
            'enabled'       => true,
            'triggerType'   => 'all',
            'stopBotFromMe' => true,
            ...$this->bot,
            ...($credentialsId !== null ? ["{$this->integration}CredsId" => $credentialsId] : []),
        ];
    }

    /**
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function filled(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
