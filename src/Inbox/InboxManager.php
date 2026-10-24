<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelException;
use Happones\Kinetix\Channels\ChannelManager;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Channels\OutgoingMessage;
use Happones\Kinetix\Channels\SendsMessages;
use Happones\Kinetix\Inbox\Events\ConversationMessageCreated;
use Happones\Kinetix\Inbox\Events\ConversationReplied;
use Happones\Kinetix\Inbox\Events\InboxUpdated;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the inbox does whatever the provider: who sees which lines,
 * recording what arrives (deduplicated, its file fetched on the queue),
 * sending — with a file, as a reply — and following delivery receipts, each
 * user's unread count, assignment, and the per-line broadcasts.
 */
class InboxManager
{
    public function __construct(protected ChannelManager $channels) {}

    // ── Visibility ────────────────────────────────────────────────────────

    /** Whether the user sees every line's conversations (admins, line managers). */
    public function seesAll(Model $user): bool
    {
        $gate = Gate::forUser($user);

        return $gate->allows('conversations.view_all') || $gate->allows('channels.manage');
    }

    /**
     * The lines whose conversations the user sees in a team: all of them,
     * or — for an agent — the ones they're assigned to.
     *
     * @return list<int|string>
     */
    public function visibleChannelIds(Model $user, int|string|null $teamKey): array
    {
        $lines = Channel::query()->where('team_id', $teamKey)->visibleTo($user);

        if (! $this->seesAll($user)) {
            $lines->whereIn('id', DB::table('kinetix_channel_agents')->where('user_id', $user->getKey())->select('channel_id'));
        }

        return $lines->pluck('id')->all();
    }

    public function seesChannel(Model $user, Channel $channel): bool
    {
        return in_array($channel->getKey(), $this->visibleChannelIds($user, $channel->team_id), false);
    }

    /**
     * The conversations a user sees in a team.
     *
     * @return Builder<Conversation>
     */
    public function conversationsFor(Model $user, int|string|null $teamKey): Builder
    {
        return Conversation::query()
            ->where('team_id', $teamKey)
            ->whereIn('channel_id', $this->visibleChannelIds($user, $teamKey));
    }

    /**
     * Narrow conversations to the ones with messages the user hasn't read.
     *
     * @param Builder<Conversation> $query
     */
    public function whereUnread(Builder $query, Model $user): void
    {
        $query->whereExists(function ($messages) use ($user): void {
            $messages->selectRaw('1')
                ->from('kinetix_conversation_messages as m')
                ->whereColumn('m.conversation_id', 'kinetix_conversations.id')
                ->where('m.direction', MessageDirection::In->value)
                ->whereRaw('m.id > coalesce((select r.last_read_message_id from kinetix_conversation_reads r where r.conversation_id = kinetix_conversations.id and r.user_id = ?), 0)', [$user->getKey()]);
        });
    }

    /**
     * Each conversation's unread count for the user: the contact's messages
     * after their own read cursor.
     *
     * @param  list<int|string>       $conversationIds
     * @return array<int|string, int>
     */
    public function unreadCounts(Model $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return DB::table('kinetix_conversation_messages as m')
            ->leftJoin('kinetix_conversation_reads as r', function ($join) use ($user): void {
                $join->on('r.conversation_id', '=', 'm.conversation_id')->where('r.user_id', '=', $user->getKey());
            })
            ->whereIn('m.conversation_id', $conversationIds)
            ->where('m.direction', MessageDirection::In->value)
            ->whereRaw('m.id > coalesce(r.last_read_message_id, 0)')
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    public function markRead(Conversation $conversation, Model $user, int|string|null $messageId = null): void
    {
        $messageId ??= $conversation->messages()->max('id');

        if ($messageId === null) {
            return;
        }

        $current = DB::table('kinetix_conversation_reads')
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $user->getKey())
            ->value('last_read_message_id');

        // A cursor only moves forward (an older tab must not unread newer messages).
        if ($current !== null && (int) $current >= (int) $messageId) {
            return;
        }

        DB::table('kinetix_conversation_reads')->updateOrInsert(
            ['conversation_id' => $conversation->getKey(), 'user_id' => $user->getKey()],
            ['last_read_message_id' => $messageId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    // ── People ────────────────────────────────────────────────────────────

    /**
     * The team's members — whom lines are given to and conversations
     * assigned to: `inbox.members` (a callable array receiving the team key),
     * else the team model's `inbox.members_relation`.
     *
     * @return Collection<int, Model>
     */
    public function members(int|string|null $teamKey): Collection
    {
        $resolver = ConfigCallback::resolve(config('kinetix.inbox.members'));

        if ($resolver !== null) {
            return collect($resolver($teamKey))->filter(static fn ($user): bool => $user instanceof Model)->values();
        }

        $teamModel = HostKeys::teamModel();
        $relation  = (string) config('kinetix.inbox.members_relation', 'members');

        if ($teamKey !== null && $teamModel !== null) {
            $team = $teamModel::query()->find($teamKey);

            return $team instanceof Model && method_exists($team, $relation)
                ? $team->{$relation}()->get()->values()
                : collect();
        }

        $userModel = config('auth.providers.users.model');

        return is_string($userModel) && class_exists($userModel) ? $userModel::query()->limit(500)->get() : collect();
    }

    /**
     * Who a conversation can be assigned to: the members who see its line.
     *
     * @return Collection<int, Model>
     */
    public function assignees(Conversation $conversation): Collection
    {
        return $this->members($conversation->team_id)
            ->filter(fn (Model $user): bool => $this->seesChannel($user, $conversation->channel))
            ->values();
    }

    /**
     * Users by key, for names in the list.
     *
     * @param  list<int|string|null>         $keys
     * @return Collection<int|string, Model>
     */
    public function users(array $keys): Collection
    {
        $keys      = array_values(array_unique(array_filter($keys, static fn ($key): bool => $key !== null)));
        $userModel = config('auth.providers.users.model');

        if ($keys === [] || ! is_string($userModel) || ! class_exists($userModel)) {
            return collect();
        }

        return $userModel::query()->whereKey($keys)->get()->keyBy(static fn (Model $user) => $user->getKey());
    }

    public function userName(?Model $user): ?string
    {
        return $user === null ? null : (string) $user->getAttribute((string) config('kinetix.inbox.name_attribute', 'name'));
    }

    public function assign(Conversation $conversation, int|string|null $userKey): void
    {
        $conversation->update(['assigned_to' => $userKey]);
        $this->broadcast($conversation);
    }

    // ── Recording ─────────────────────────────────────────────────────────

    /** Record a message that reached a line (once, however often the provider repeats it). */
    public function recordInbound(Channel $channel, InboundMessage $inbound): ?ConversationMessage
    {
        $conversation = $this->conversationFor($channel, $inbound);

        if ($inbound->externalId !== null && $conversation->messages()->where('external_id', $inbound->externalId)->exists()) {
            return null;
        }

        try {
            $message = $conversation->messages()->create([
                'direction'   => MessageDirection::In,
                'type'        => $inbound->type,
                'body'        => $inbound->text,
                'media_mime'  => $inbound->mediaMime,
                'media_name'  => $inbound->mediaName,
                'media_ref'   => $inbound->mediaRef,
                'status'      => MessageStatus::Received,
                'external_id' => $inbound->externalId,
                'reply_to_id' => $this->replyTarget($conversation, $inbound->replyTo),
                'sent_at'     => $inbound->receivedAt,
            ]);
        } catch (QueryException) {
            // The same message raced in twice: the unique index kept one.
            return null;
        }

        $conversation->archived_at = null;
        $this->touch($conversation, $message);

        if ($inbound->hasMedia()) {
            DownloadConversationMedia::dispatch($message->getKey());
        }

        ConversationMessageCreated::dispatch($conversation, $message);
        $this->broadcast($conversation, $message);

        return $message;
    }

    /**
     * A message the line sent outside the inbox, recorded as sent, once:
     * typed on its phone (a person answering), sent by a bot the provider
     * runs on it (signed with the bot's name), or sent through the provider's
     * API by something else (unsigned). One the inbox sent itself, reported
     * back before its id was stored, is matched instead.
     */
    public function recordSent(Channel $channel, InboundMessage $sent): ?ConversationMessage
    {
        $conversation = $this->conversationFor($channel, $sent);

        if ($sent->externalId !== null && $conversation->messages()->where('external_id', $sent->externalId)->exists()) {
            return null;
        }

        if ($this->claimOwnSend($conversation, $sent)) {
            return null;
        }

        $bot = $channel->meta['bot'] ?? null;

        try {
            $message = $conversation->messages()->create([
                'direction'   => MessageDirection::Out,
                'type'        => $sent->type,
                'body'        => $sent->text,
                'media_mime'  => $sent->mediaMime,
                'media_name'  => $sent->mediaName,
                'media_ref'   => $sent->mediaRef,
                'status'      => MessageStatus::Sent,
                'external_id' => $sent->externalId,
                'reply_to_id' => $this->replyTarget($conversation, $sent->replyTo),
                'meta'        => match (true) {
                    $sent->fromPhone => ['from_phone' => true],
                    is_array($bot)   => array_filter(['bot' => true, 'author_name' => is_string($bot['name'] ?? null) ? $bot['name'] : null]),
                    default          => null,
                },
                'sent_at' => $sent->receivedAt,
            ]);
        } catch (QueryException) {
            return null;
        }

        $this->touch($conversation, $message);

        if ($sent->hasMedia()) {
            DownloadConversationMedia::dispatch($message->getKey());
        }

        $this->broadcast($conversation, $message);

        // Someone answered from the phone: a flow talking there steps back.
        if ($sent->fromPhone) {
            ConversationReplied::dispatch($conversation, $message);
        }

        return $message;
    }

    /**
     * The inbox's own send reported back by the line before its id was
     * stored (or after a timeout marked it failed although it went out): the
     * oldest recent unconfirmed message with the same content takes the id.
     */
    protected function claimOwnSend(Conversation $conversation, InboundMessage $sent): bool
    {
        if ($sent->externalId === null) {
            return false;
        }

        $text = trim((string) $sent->text);
        $own  = $conversation->messages()
            ->where('direction', MessageDirection::Out->value)
            ->whereNull('external_id')
            ->whereIn('status', [MessageStatus::Pending->value, MessageStatus::Failed->value])
            ->where('created_at', '>=', now()->subMinutes(5))
            ->oldest('id')
            ->get()
            ->first(static fn (ConversationMessage $message): bool => trim((string) $message->body) === $text
                && $message->hasMedia()                                                             === $sent->hasMedia());

        if ($own === null) {
            return false;
        }

        $own->update($own->status === MessageStatus::Failed
            ? ['external_id' => $sent->externalId, 'status' => MessageStatus::Sent, 'error' => null]
            : ['external_id' => $sent->externalId]);

        $this->broadcast($conversation, $own);

        return true;
    }

    /**
     * A message deleted for everyone: kept in the thread, marked deleted, so
     * the team still knows what was said and that it was taken back.
     */
    public function recordDeleted(Channel $channel, string $externalId): void
    {
        $message = ConversationMessage::query()
            ->where('external_id', $externalId)
            ->whereIn('conversation_id', Conversation::query()->where('channel_id', $channel->getKey())->select('id'))
            ->first();

        if ($message === null || isset($message->meta['deleted_at'])) {
            return;
        }

        $message->update(['meta' => [...($message->meta ?? []), 'deleted_at' => now()->toIso8601String()]]);
        $this->broadcast($message->conversation, $message);
    }

    /** A delivery receipt: a message only ever moves forward, or to failed before it was delivered. */
    public function recordStatus(Channel $channel, string $externalId, string $status): void
    {
        $next = MessageStatus::tryFrom($status);

        $message = ConversationMessage::query()
            ->where('external_id', $externalId)
            ->whereIn('conversation_id', Conversation::query()->where('channel_id', $channel->getKey())->select('id'))
            ->first();

        if ($next === null || $message === null || $message->direction !== MessageDirection::Out) {
            return;
        }

        $forward = $next === MessageStatus::Failed
            ? $message->status->rank() < MessageStatus::Delivered->rank()
            : $next->rank() > $message->status->rank();

        if ($forward) {
            $message->update(['status' => $next]);
            $this->broadcast($message->conversation, $message);
        }
    }

    protected function conversationFor(Channel $channel, InboundMessage $inbound): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['channel_id' => $channel->getKey(), 'contact_key' => $inbound->from],
            ['team_id' => $channel->team_id, 'contact_name' => $inbound->fromName, 'is_group' => $inbound->isGroup],
        );

        if ($inbound->fromName !== null && $conversation->contact_name !== $inbound->fromName && ! $inbound->isGroup) {
            $conversation->contact_name = $inbound->fromName;
        }

        if ($conversation->wasRecentlyCreated) {
            $this->linkContact($conversation);
        }

        return $conversation;
    }

    /** Link a new conversation to the app's own contact, through `inbox.contact_resolver`. */
    protected function linkContact(Conversation $conversation): void
    {
        $resolver = ConfigCallback::resolve(config('kinetix.inbox.contact_resolver'));
        $contact  = $resolver !== null ? rescue(fn () => $resolver($conversation), null) : null;

        if ($contact instanceof Model) {
            $conversation->contactable()->associate($contact);
            $conversation->save();
        }
    }

    protected function replyTarget(Conversation $conversation, ?string $externalId): int|string|null
    {
        return $externalId === null ? null : $conversation->messages()->where('external_id', $externalId)->value('id');
    }

    protected function touch(Conversation $conversation, ConversationMessage $message): void
    {
        $conversation->last_message_id = $message->getKey();
        $conversation->last_message_at = $message->sent_at ?? now();
        $conversation->save();
    }

    // ── Sending ───────────────────────────────────────────────────────────

    /**
     * What `{{variable}}` placeholders in a message stand for: the contact's
     * name and number, the agent's and the line's name, plus the app's own
     * from `inbox.variables` — a callable array receiving the conversation
     * and the author, returning `['negocio' => 'Acme', …]`.
     *
     * @return array<string, string>
     */
    public function variables(Conversation $conversation, ?Model $author = null): array
    {
        $variables = [
            'contact.name' => (string) ($conversation->contact_name ?? ''),
            'contact.key'  => (string) $conversation->contact_key,
            'agent.name'   => (string) ($this->userName($author) ?? ''),
            'line.name'    => (string) ($conversation->channel->name ?? ''),
        ];

        $callback = ConfigCallback::resolve(config('kinetix.inbox.variables'));

        foreach ($callback !== null ? (array) $callback($conversation, $author) : [] as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $variables[$name] = (string) $value;
            }
        }

        return $variables;
    }

    /**
     * Fill the placeholders a message still carries (a quick reply typed
     * through the API, or an app variable the composer doesn't know). One it
     * doesn't know stays as written.
     */
    public function fillVariables(Conversation $conversation, ?Model $author, string $text): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $variables = $this->variables($conversation, $author);

        return (string) preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/u',
            static fn (array $match): string => $variables[$match[1]] ?? $match[0],
            $text,
        );
    }

    /**
     * The conversation with a contact on a line, opened when there's none
     * yet — for the business writing first (a reminder, a campaign). A
     * WhatsApp number is kept as digits, the way contacts' messages file it.
     *
     *     $inbox->send($inbox->conversationWith($line, '+52 55 1234 5678', 'Ada'), null, 'Tu cita es mañana');
     */
    public function conversationWith(Channel $channel, string $contactKey, ?string $contactName = null): Conversation
    {
        $isGroup = str_ends_with($contactKey, '@g.us');
        $key     = $channel->provider === 'whatsapp' && ! $isGroup ? (string) preg_replace('/\D/', '', $contactKey) : trim($contactKey);

        if ($key === '') {
            throw new ChannelException(__('kinetix::kinetix.inbox_error_contact_required'));
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['channel_id' => $channel->getKey(), 'contact_key' => $key],
            ['team_id' => $channel->team_id, 'contact_name' => $contactName, 'is_group' => $isGroup],
        );

        if ($contactName !== null && $conversation->contact_name === null) {
            $conversation->update(['contact_name' => $contactName]);
        }

        return $conversation;
    }

    /**
     * Send from the team — text and/or a file, optionally answering a
     * message — or write an internal note. The message is stored first, so a
     * refusal leaves it in the thread, failed, to retry.
     */
    public function send(Conversation $conversation, ?Model $author, ?string $text, ?UploadedFile $file = null, ?ConversationMessage $replyTo = null, bool $note = false): ConversationMessage
    {
        $media = $file !== null ? $this->store($conversation, $file) : [];
        $text  = $text !== null ? $this->fillVariables($conversation, $author, $text) : null;

        $message = $conversation->messages()->create([
            'direction'   => $note ? MessageDirection::Note : MessageDirection::Out,
            'type'        => $file !== null ? $this->typeOf((string) $file->getMimeType()) : 'text',
            'body'        => $text !== null && trim($text) !== '' ? $text : null,
            'status'      => $note ? MessageStatus::Sent : MessageStatus::Pending,
            'reply_to_id' => $replyTo?->getKey(),
            'author_id'   => $author?->getKey(),
            'sent_at'     => now(),
            ...$media,
        ]);

        $this->touch($conversation, $message);

        if ($note) {
            $this->broadcast($conversation, $message);
        } else {
            $this->deliver($message);

            // A person answered: whatever bot was talking steps back. Your
            // code writing (no author: a reminder) isn't a person taking over.
            if ($author !== null) {
                ConversationReplied::dispatch($conversation, $message);
            }
        }

        return $message->refresh();
    }

    /**
     * Send as a bot (a flow): text, optionally offering options to pick, or
     * an internal note. Signed with the bot's name; never pauses a bot.
     *
     * @param list<string> $options
     */
    public function sendAsBot(Conversation $conversation, string $text, string $name, array $options = [], bool $note = false): ConversationMessage
    {
        $outgoing = new OutgoingMessage($conversation->contact_key, $text, options: $options);

        $message = $conversation->messages()->create([
            'direction' => $note ? MessageDirection::Note : MessageDirection::Out,
            'type'      => 'text',
            'body'      => $outgoing->textWithOptions(),
            'status'    => $note ? MessageStatus::Sent : MessageStatus::Pending,
            'meta'      => ['bot' => true, 'author_name' => $name, 'prompt' => $text, 'options' => $options],
            'sent_at'   => now(),
        ]);

        $this->touch($conversation, $message);

        $note ? $this->broadcast($conversation, $message) : $this->deliver($message);

        return $message->refresh();
    }

    /** Hand a stored message to its line's provider, recording the outcome. */
    public function deliver(ConversationMessage $message): void
    {
        $conversation = $message->conversation;
        $channel      = $conversation->channel;

        try {
            $driver   = $this->channels->for($channel);
            $options  = array_values(array_filter((array) ($message->meta['options'] ?? []), 'is_string'));
            $outgoing = new OutgoingMessage(
                to: $conversation->contact_key,
                // A menu goes as its prompt plus options (buttons where the line has them).
                text: $options                      !== [] ? (string) ($message->meta['prompt'] ?? $message->body) : $message->body,
                mediaContents: $message->media_path !== null ? Storage::disk($this->disk())->get($message->media_path) : null,
                mediaMime: $message->media_mime,
                mediaName: $message->media_name,
                mediaType: $message->type === 'sticker' ? 'image' : $message->type,
                replyTo: $message->replyTo?->external_id,
                options: $options,
            );

            if ($driver instanceof SendsMessages) {
                $externalId = $driver->send($channel, $outgoing);
            } elseif (! $outgoing->hasMedia()) {
                $driver->sendText($channel, $outgoing->to, (string) $outgoing->text);
                $externalId = null;
            } else {
                throw new ChannelException(__('kinetix::kinetix.inbox_error_media_unsupported'));
            }

            $message->update(['status' => MessageStatus::Sent, 'external_id' => $externalId, 'error' => null]);
        } catch (ChannelException $exception) {
            $message->update(['status' => MessageStatus::Failed, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::warning('Kinetix inbox: sending failed.', ['message' => $message->getKey(), 'error' => $exception->getMessage()]);
            $message->update(['status' => MessageStatus::Failed, 'error' => __('kinetix::kinetix.channel_error_unreachable')]);
        }

        $this->broadcast($conversation, $message);
    }

    /**
     * Store an uploaded file on the inbox disk.
     *
     * @return array{media_path: string, media_mime: string|null, media_name: string, media_size: int|false}
     */
    protected function store(Conversation $conversation, UploadedFile $file): array
    {
        $directory = trim((string) config('kinetix.inbox.directory', 'kinetix/inbox'), '/')."/{$conversation->getKey()}";
        $name      = Str::ulid().($file->extension() ? '.'.$file->extension() : '');

        return [
            'media_path' => (string) $file->storeAs($directory, $name, ['disk' => $this->disk()]),
            'media_mime' => $file->getMimeType(),
            'media_name' => $file->getClientOriginalName(),
            'media_size' => $file->getSize(),
        ];
    }

    public function typeOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'document',
        };
    }

    /** Where message files live: `inbox.disk`, else Kinetix's private disk. */
    public function disk(): string
    {
        return (string) (config('kinetix.inbox.disk') ?: config('kinetix.filesystem.private_disk', 'local'));
    }

    // ── Broadcasting ──────────────────────────────────────────────────────

    /** The private channel a line's conversations update on; null when broadcasting is off. */
    public function broadcastChannel(int|string $channelId): ?string
    {
        return config('kinetix.channels.broadcast', false) ? "kinetix.inbox.{$channelId}" : null;
    }

    public function broadcast(Conversation $conversation, ?ConversationMessage $message = null): void
    {
        $name = $this->broadcastChannel($conversation->channel_id);

        if ($name === null) {
            return;
        }

        try {
            event(new InboxUpdated($name, [
                'conversationId' => $conversation->getKey(),
                'channelId'      => $conversation->channel_id,
                'messageId'      => $message?->getKey(),
            ]));
        } catch (Throwable $exception) {
            Log::warning('Kinetix inbox: broadcast failed.', ['error' => $exception->getMessage()]);
        }
    }
}
