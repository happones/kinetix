<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Data\ConversationData;
use Happones\Kinetix\Data\ConversationMessageData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The team inbox, gated by `conversations.view`: each user sees the
 * conversations of the lines they answer (every line with
 * `conversations.view_all` or `channels.manage`), with their own unread
 * counts. Send, reply to a message, attach a file, write internal notes,
 * assign, archive.
 */
class InboxController
{
    public function __construct(protected InboxManager $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $data = $request->validate([
            'search'  => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable'],
            'filter'  => ['nullable', Rule::in(['all', 'unread', 'mine', 'unassigned', 'archived'])],
            'page'    => ['nullable', 'integer', 'min:1'],
        ]);

        $query  = $this->inbox->conversationsFor($user, $this->teamId());
        $filter = $data['filter'] ?? 'all';

        $filter === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        match ($filter) {
            'unread'     => $this->inbox->whereUnread($query, $user),
            'mine'       => $query->where('assigned_to', $user->getKey()),
            'unassigned' => $query->whereNull('assigned_to'),
            default      => null,
        };

        if (! empty($data['channel'])) {
            $query->where('channel_id', $data['channel']);
        }

        if (($search = trim((string) ($data['search'] ?? ''))) !== '') {
            $this->search($query, $search);
        }

        $page = $query->with(['channel', 'lastMessage.replyTo'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->simplePaginate((int) config('kinetix.inbox.page_size', 25));

        $lines = Channel::query()->whereKey($this->inbox->visibleChannelIds($user, $this->teamId()))->orderBy('name')->get();

        return response()->json([
            'conversations' => $this->present($page->getCollection(), $user),
            'hasMore'       => $page->hasMorePages(),
            'channels'      => $lines->map(fn (Channel $line): array => [
                'id'        => $line->getKey(),
                'provider'  => $line->provider,
                'name'      => $line->name,
                'status'    => $line->status->value,
                'broadcast' => $this->inbox->broadcastChannel($line->getKey()),
            ])->values(),
            'me'   => ['id' => $user->getKey(), 'seesAll' => $this->inbox->seesAll($user)],
            'poll' => (array) config('kinetix.inbox.poll', ['list' => 15, 'thread' => 5]),
            // Whether bots can be started and stopped from the thread.
            'flows'    => (bool) config('kinetix.flows.enabled', false),
            'composer' => [
                'maxUploadKb' => (int) config('kinetix.inbox.max_upload_kb', 16384),
                'accept'      => implode(',', (array) config('kinetix.inbox.accepted_mimes', [])),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $conversation = $this->find($request);

        return response()->json(['conversation' => $this->present(new Collection([$conversation]), $this->user())[0]]);
    }

    /** A page of messages, newest last: `?before=` an id loads older ones, `?after=` newer. */
    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->find($request);
        $data         = $request->validate(['before' => ['nullable', 'integer'], 'after' => ['nullable', 'integer']]);
        $size         = (int) config('kinetix.inbox.messages_page_size', 40);

        $query = $conversation->messages()->with('replyTo');

        if (isset($data['after'])) {
            $messages = $query->where('id', '>', $data['after'])->orderBy('id')->limit($size)->get();
            $hasMore  = false;
        } else {
            if (isset($data['before'])) {
                $query->where('id', '<', $data['before']);
            }

            $messages = $query->orderByDesc('id')->limit($size + 1)->get();
            $hasMore  = $messages->count() > $size;
            $messages = $messages->take($size)->reverse()->values();
        }

        return response()->json(['messages' => $this->presentMessages($messages), 'hasMore' => $hasMore]);
    }

    public function message(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->presentMessages(new Collection([$this->findMessage($request)]))[0]]);
    }

    public function send(Request $request): JsonResponse
    {
        $conversation = $this->find($request);
        $maxKb        = (int) config('kinetix.inbox.max_upload_kb', 16384);
        $mimes        = (array) config('kinetix.inbox.accepted_mimes', []);

        $data = $request->validate([
            'body'       => ['nullable', 'string', 'max:4096', 'required_without:attachment'],
            'attachment' => ['nullable', 'file', "max:{$maxKb}", ...($mimes !== [] ? ['mimetypes:'.implode(',', $mimes)] : [])],
            'reply_to'   => ['nullable', 'integer'],
            'note'       => ['nullable', 'boolean'],
        ]);

        $note = (bool) ($data['note'] ?? false);

        if ($note && $request->hasFile('attachment')) {
            throw ValidationException::withMessages(['attachment' => __('kinetix::kinetix.inbox_error_note_attachment')]);
        }

        $replyTo = isset($data['reply_to'])
            ? $conversation->messages()->whereKey($data['reply_to'])->first()
            : null;

        $message = $this->inbox->send($conversation, $this->user(), $data['body'] ?? null, $request->file('attachment'), $replyTo, $note);

        return response()->json(['message' => $this->presentMessages(new Collection([$message]))[0]], 201);
    }

    public function retry(Request $request): JsonResponse
    {
        $message = $this->findMessage($request);

        abort_unless($message->direction === MessageDirection::Out && $message->status === MessageStatus::Failed, 422);

        $message->update(['status' => MessageStatus::Pending, 'error' => null]);
        $this->inbox->deliver($message);

        return response()->json(['message' => $this->presentMessages(new Collection([$message->refresh()]))[0]]);
    }

    /** A message's file, streamed (`?download=1` to save it). */
    public function media(Request $request): Response
    {
        $message = $this->findMessage($request);
        $disk    = Storage::disk($this->inbox->disk());

        abort_if($message->media_path === null || ! $disk->exists($message->media_path), 404);

        $name    = $message->media_name ?: basename($message->media_path);
        $headers = ['Content-Type' => $message->media_mime ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('download')
            ? $disk->download($message->media_path, $name, $headers)
            : $disk->response($message->media_path, $name, $headers);
    }

    public function read(Request $request): JsonResponse
    {
        $conversation = $this->find($request);
        $messageId    = $request->validate(['message_id' => ['nullable', 'integer']])['message_id'] ?? null;

        $this->inbox->markRead($conversation, $this->user(), $messageId);

        return response()->json(['status' => 'ok']);
    }

    public function assignees(Request $request): JsonResponse
    {
        $conversation = $this->find($request);

        return response()->json([
            'assignees' => $this->inbox->assignees($conversation)
                ->map(fn (Model $user): array => ['id' => $user->getKey(), 'name' => $this->inbox->userName($user)])
                ->values(),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $conversation = $this->find($request);
        $userKey      = $request->validate(['user_id' => ['nullable']])['user_id'] ?? null;

        if ($userKey !== null && ! $this->inbox->assignees($conversation)->contains(fn (Model $user): bool => (string) $user->getKey() === (string) $userKey)) {
            throw ValidationException::withMessages(['user_id' => __('kinetix::kinetix.inbox_error_assignee')]);
        }

        $this->inbox->assign($conversation, $userKey);

        return $this->show($request);
    }

    public function archive(Request $request): JsonResponse
    {
        $this->find($request)->update(['archived_at' => now()]);

        return $this->show($request);
    }

    public function unarchive(Request $request): JsonResponse
    {
        $this->find($request)->update(['archived_at' => null]);

        return $this->show($request);
    }

    /**
     * @param Builder<Conversation> $query
     */
    protected function search(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('contact_name', 'like', $like)
                ->orWhere('contact_key', 'like', $like)
                ->orWhereHas('messages', fn (Builder $messages) => $messages->where('body', 'like', $like)->where('direction', '!=', MessageDirection::Note->value));
        });
    }

    /**
     * @param  Collection<int, Conversation> $conversations
     * @return list<ConversationData>
     */
    protected function present(Collection $conversations, Model $user): array
    {
        $unread = $this->inbox->unreadCounts($user, $conversations->modelKeys());
        $names  = $this->inbox->users($conversations->pluck('assigned_to')->all());

        return $conversations->map(fn (Conversation $conversation): ConversationData => ConversationData::fromModel(
            $conversation->loadMissing(['channel', 'lastMessage.replyTo']),
            $unread[$conversation->getKey()] ?? 0,
            $this->inbox->userName($names->get($conversation->assigned_to)),
        ))->values()->all();
    }

    /**
     * @param  Collection<int, ConversationMessage> $messages
     * @return list<ConversationMessageData>
     */
    protected function presentMessages(Collection $messages): array
    {
        $authors = $this->inbox->users($messages->pluck('author_id')->all());

        return $messages->map(fn (ConversationMessage $message): ConversationMessageData => ConversationMessageData::fromModel(
            $message->loadMissing('replyTo'),
            $this->inbox->userName($authors->get($message->author_id)),
        ))->values()->all();
    }

    protected function find(Request $request): Conversation
    {
        return $this->inbox->conversationsFor($this->user(), $this->teamId())
            ->whereKey($request->route('conversation'))
            ->firstOrFail();
    }

    protected function findMessage(Request $request): ConversationMessage
    {
        return ConversationMessage::query()
            ->whereKey($request->route('message'))
            ->whereIn('conversation_id', $this->inbox->conversationsFor($this->user(), $this->teamId())->select('id'))
            ->firstOrFail();
    }

    protected function user(): Model
    {
        Gate::authorize('conversations.view');

        $user = auth()->user();
        abort_unless($user instanceof Model, 403);

        return $user;
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('channels');
    }
}
