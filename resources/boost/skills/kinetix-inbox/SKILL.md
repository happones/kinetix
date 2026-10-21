---
name: kinetix-inbox
description: "Team inbox on Kinetix Channels lines: conversations, per-user unread, line agents, replies with files and quotes, quick replies, internal notes, assignment, archive, receipts. Activates when working with KinetixInbox, useKinetixInbox, conversations, ConversationMessageCreated or line agents."
license: MIT
metadata:
  author: happones
---

# Kinetix Inbox Development

## When to Apply

Activate this skill when:

- Mounting `<KinetixInbox>` or using `useKinetixInbox`.
- Working with `Conversation` / `ConversationMessage`, `InboxManager`, line agents or `ConversationMessageCreated`.
- Changing how messages are recorded, sent, or how receipts and media flow.

## Documentation

For full details, reference `docs/inbox.md` (published at https://happones.github.io/kinetix/inbox).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-inbox-migrations   # needs kinetix-channels-migrations
```

```php
'inbox' => [
    'enabled' => env('KINETIX_INBOX_ENABLED', false),   // also needs kinetix.channels.enabled
    'disk' => null,                                    // null = filesystem.private_disk
    'directory' => 'kinetix/inbox',
    'max_upload_kb' => 16384, 'accepted_mimes' => [...],
    'members' => null, 'members_relation' => 'members', 'name_attribute' => 'name',
    'contact_resolver' => null,                        // [Class::class, 'method'](Conversation) → ?Model
    'poll' => ['list' => 15, 'thread' => 5],
],
```

## Rules

- **Tables**:
    - `kinetix_conversations` (line × `contact_key`, unique)
    - `kinetix_conversation_messages` (direction `in|out|note`; status `received|pending|sent|delivered|read|failed`; unique `conversation_id+external_id`)
    - `kinetix_conversation_reads` (per-user cursor)
    - `kinetix_channel_agents`
    - `kinetix_quick_replies` (team × `shortcut`, unique)
- **Visibility**: always go through `InboxManager::conversationsFor($user, $teamKey)` and `visibleChannelIds()`.
    - `conversations.view_all` or `channels.manage` sees every line.
    - `conversations.view` sees only lines where the user is an agent.
    - The same rules apply to endpoints, media and the broadcast channel `kinetix.inbox.{channelId}`.
- **Recording**: listeners on `ChannelMessageReceived` / `ChannelMessageStatusUpdated` call `recordInbound` / `recordStatus`.
    - Dedupe by `external_id`.
    - Media goes through the queued `DownloadConversationMedia` → driver `DownloadsMedia`, onto the private disk.
    - Receipts only move forward (`MessageStatus::rank()`).
- **Sending**: `InboxManager::send($conversation, $author, $text, $file, $replyTo, $note)`.
    - It stores the message first (pending), then `deliver()` → driver `SendsMessages::send(OutgoingMessage)` returns the external id.
    - Failures stay `failed` with an `error`; retry with `deliver()`.
    - Notes never call the provider.
- **Broadcast** payloads are ids only (`conversationId`, `channelId`, `messageId`); the client refetches through the authorized endpoints.
- **Hook for bots**: `ConversationMessageCreated` (contact messages only). Reply via `InboxManager::send` so it shows in the thread.
- **Frontend**:
    - `<KinetixInbox>` = `Inbox/KinetixInboxList` + `KinetixInboxThread` (+ `KinetixInboxBubble`, `KinetixInboxComposer`).
    - Optimistic send uses `localId`.
    - Images dispatch a cancelable `kinetix:preview`; if unhandled, they open in a new tab.
- **Quick replies**:
    - Endpoints: `inbox/replies` (GET `{replies, canManage}` for `conversations.view`; POST/PUT/DELETE behind `conversations.quick_replies`).
    - Shortcuts are stored lowercased without the `/`; `[\pL\pN_-]+`, unique per team.
    - The composer suggests on `/word` before the caret and fills `{{contact.name}}`, `{{contact.key}}`, `{{agent.name}}`, `{{line.name}}` on insert. `KinetixInboxQuickReplies` is the manager modal.
- **Messages sent outside the inbox** (WhatsApp `fromMe` on `messages.upsert`, and `send.message`):
    - The driver returns them as `WebhookResult::$sent` → `ChannelMessageSent` → `InboxManager::recordSent()` (`inbox.mirror_sent`, default on).
    - Phone sends get `meta.from_phone` and fire `ConversationReplied`. A provider-run bot's get `meta.bot` with the line's `meta.bot.name`.
    - The inbox's own sends coming back are deduped by `external_id`, or claimed by `claimOwnSend()` (oldest recent pending/failed Out message, same body). Never count as unread.
