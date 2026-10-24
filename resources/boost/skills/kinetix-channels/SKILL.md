---
name: kinetix-channels
description: "Messaging lines for SaaS teams: link WhatsApp numbers (Evolution API, QR or pairing code) and Telegram bots (token), receive normalized messages, send replies. Activates when working with KinetixChannelManager, ChannelMessageReceived, channel drivers or Evolution/Telegram integrations."
license: MIT
metadata:
  author: happones
---

# Kinetix Channels Development

## When to Apply

Activate this skill when:

- Mounting `<KinetixChannelManager>` / `<KinetixChannelLinker>` or using `useKinetixChannels`.
- Handling inbound messages (`ChannelMessageReceived`) or sending (`KinetixChannels::sendText`).
- Adding a provider (`KinetixChannels::extend`) or touching the WhatsApp (Evolution) / Telegram drivers.

## Documentation

For full details, reference `docs/channels.md` (published at https://happones.github.io/kinetix/channels).

## Configuration

```bash
composer require happones/laravel-evolution-client   # WhatsApp only (^4.0)
php artisan vendor:publish --tag=kinetix-channels-migrations
```

```php
'channels' => [
    'enabled' => env('KINETIX_CHANNELS_ENABLED', false),
    'teams'   => env('KINETIX_CHANNELS_TEAMS'),       // null = inherit kinetix.teams
    'limit'   => null,                                // int | [Class::class, 'method'](teamKey) | null
    'webhook_url' => env('KINETIX_CHANNELS_WEBHOOK_URL'), // tunnel in dev; Telegram needs HTTPS
    'check_interval' => 5,                            // status endpoint asks the provider every N s
    'phone_country' => env('KINETIX_CHANNELS_PHONE_COUNTRY'), // pairing-code number's country; null = browser region
    'broadcast' => env('KINETIX_CHANNELS_BROADCAST', false), // channel.updated on private kinetix.channels.{team}
    'providers' => [
        'whatsapp' => ['enabled' => true, 'driver' => WhatsAppDriver::class, 'base_url' => null, 'api_key' => null, 'qr_ttl' => 30,
                       'bot' => null], // definition | [Class::class, 'method'](Channel) → ?WhatsAppBot
        'telegram' => ['enabled' => true, 'driver' => TelegramDriver::class, 'api_url' => 'https://api.telegram.org'],
    ],
],
```

## Rules

- **Lines are `Channel` models** (`kinetix_channels`): team-scoped via
  `KinetixTeams::keyFor('channels')`; `credentials` is `encrypted:array` and,
  with `webhook_secret`, never serialized (`ChannelData`).
- **Webhook**: `POST {prefix}/channels/webhook/{public_id}`, outside the web
  middleware, verified per line (`X-Kinetix-Channel-Secret` for Evolution,
  `X-Telegram-Bot-Api-Secret-Token` for Telegram). Unknown line and bad secret
  both answer 401.
- **Inbound**: listen for `ChannelMessageReceived` (`InboundMessage`: from,
  fromName, text, type, externalId, isGroup, receivedAt, raw). Own messages are
  skipped. Reply with `KinetixChannels::sendText($channel, $message->from, …)`.
  Queue heavy listeners.
- **Status**: `ChannelManager::transition()` is the one way to change it (fires
  `ChannelStatusChanged`, broadcasts). The polled status endpoint syncs with the
  provider at most every `check_interval` s; schedule `kinetix:channels:sync`.
- **Evolution**: always through `EvolutionGateway` (default
  `EvolutionClientGateway` builds a per-instance client — never mutate the
  package singleton). Instance name = `instance_prefix` + lower(public_id).
  Bind a fake gateway in tests.
- **Provider-run bots (WhatsApp)**: an Evolution integration on the line, for apps whose bot lives behind the scenes.
  - Integrations: `evolutionBot`, `typebot`, `openai`, `dify`, `flowise`, `n8n`, `evoai`.
  - Build them with `WhatsAppBot::evolutionBot|typebot|openai|dify|flowise|n8n|evoai|make()`.
  - `providers.whatsapp.bot` attaches one on provisioning. A failure removes the instance and the line isn't created.
  - Existing lines: `KinetixChannels::attachBot($line, $bot)` / `detachBot($line)`.
  - Stored in `meta.bot` (not serialized); `Channel::hasExternalBot()`.
  - Flows don't auto-start on such lines.
  - Needs a gateway implementing `EvolutionBotGateway`.
  - Defaults: `enabled`, `triggerType: all`, `stopBotFromMe: true`.
- **Telegram**: bot tokens only (getMe → setWebhook with secret_token). No
  MTProto / personal-account login.
- **Optional driver interfaces** (used by the inbox):
  - `SendsMessages::send(Channel, OutgoingMessage): ?string` returns the external id;
  - `DownloadsMedia::downloadMedia(Channel, $mediaRef)`;
  - `ReportsHandle::handle(Channel)`.
  - Fill `InboundMessage` `replyTo` / `mediaRef` / `mediaMime` / `mediaName`, and `WebhookResult::$statuses` → `ChannelMessageStatusUpdated`.
  - WhatsApp subscribes `MESSAGES_UPDATE`; `restart()` re-registers the webhook.
- **New provider**: implement `ChannelDriver`, `KinetixChannels::extend('key', Driver::class)`,
  add `channel_provider_{key}` translation. Throw `ChannelException` (message
  shown to users; `forField('token', …)` for a field error).
- Endpoints: index, store, update (name), destroy, `{id}/link?phone=`,
  `{id}/status`, `{id}/disconnect`, `{id}/restart`, `{id}/test` — all
  `channels.manage`.
- **WhatsApp options**: `direct_only` (1:1 chats only; `status@broadcast` always dropped), `typing` (Evolution `delay` = min_ms + ms_per_char × length, ≤ max_ms), `webhook_events` (extra events; child drivers override `webhookEvents()`). A provider 4xx → `ChannelException` with `refusedByProvider` and the body's `response.message`.
