<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels\Evolution;

/**
 * The Evolution API calls behind a line's own bot ({@see WhatsAppBot}): its
 * integrations share one shape — `/{integration}/create|settings|delete` —
 * so these take the integration's name. Separate from {@see EvolutionGateway}
 * so gateways written before it keep working; a gateway without it can't
 * attach bots.
 */
interface EvolutionBotGateway
{
    /**
     * Create a bot on the instance.
     *
     * @param  array<string, mixed> $bot
     * @return string               The bot's id on the server.
     */
    public function createBot(string $instance, string $integration, array $bot): string;

    /**
     * Store an integration's credentials (OpenAI's API key) for its bots.
     *
     * @return string The credentials' id on the server.
     */
    public function createBotCredentials(string $instance, string $integration, string $name, string $apiKey): string;

    /**
     * The integration's defaults for the instance.
     *
     * @param array<string, mixed> $settings
     */
    public function botSettings(string $instance, string $integration, array $settings): void;

    public function deleteBot(string $instance, string $integration, string $botId): void;
}
