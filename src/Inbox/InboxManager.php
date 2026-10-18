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
        $lines = Channel::query()->where('team_id', $teamKey);

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
     * Send from the team — text and/or a file, optionally answering a
     * message — or write an internal note. The message is stored first, so a
     * refusal leaves it in the thread, failed, to retry.
     */
    public function send(Conversation $conversation, Model $author, ?string $text, ?UploadedFile $file = null, ?ConversationMessage $replyTo = null, bool $note = false): ConversationMessage
    {
        $media = $file !== null ? $this->store($conversation, $file) : [];

        $message = $conversation->messages()->create([
            'direction'   => $note ? MessageDirection::Note : MessageDirection::Out,
            'type'        => $file !== null ? $this->typeOf((string) $file->getMimeType()) : 'text',
            'body'        => $text !== null && trim($text) !== '' ? $text : null,
            'status'      => $note ? MessageStatus::Sent : MessageStatus::Pending,
            'reply_to_id' => $replyTo?->getKey(),
            'author_id'   => $author->getKey(),
            'sent_at'     => now(),
            ...$media,
        ]);

        $this->touch($conversation, $message);

        if ($note) {
            $this->broadcast($conversation, $message);
        } else {
            $this->deliver($message);
        }

        return $message->refresh();
    }

    /** Hand a stored message to its line's provider, recording the outcome. */
    public function deliver(ConversationMessage $message): void
    {
        $conversation = $message->conversation;
        $channel      = $conversation->channel;

        try {
            $driver   = $this->channels->for($channel);
            $outgoing = new OutgoingMessage(
                to: $conversation->contact_key,
                text: $message->body,
                mediaContents: $message->media_path !== null ? Storage::disk($this->disk())->get($message->media_path) : null,
                mediaMime: $message->media_mime,
                mediaName: $message->media_name,
                mediaType: $message->type === 'sticker' ? 'image' : $message->type,
                replyTo: $message->replyTo?->external_id,
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
