<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The team's quick replies: everyone in the inbox (`conversations.view`)
 * uses them; `conversations.quick_replies` creates, edits and deletes them.
 */
class QuickReplyController
{
    public function index(): JsonResponse
    {
        Gate::authorize('conversations.view');

        return response()->json([
            'replies'   => $this->scoped()->orderBy('shortcut')->get()->map(static fn (QuickReply $reply): array => $reply->toPayload())->values(),
            'canManage' => Gate::allows('conversations.quick_replies'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('conversations.quick_replies');

        $reply = $this->scoped()->create([
            ...$this->validated($request),
            'team_id'    => $this->teamId(),
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['reply' => $reply->toPayload()], 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('conversations.quick_replies');

        $reply = $this->find($request);
        $reply->update($this->validated($request, $reply));

        return response()->json(['reply' => $reply->toPayload()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('conversations.quick_replies');

        $this->find($request)->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * A shortcut is a single word (letters, numbers, - and _), unique in the team.
     *
     * @return array{shortcut: string, body: string}
     */
    protected function validated(Request $request, ?QuickReply $reply = null): array
    {
        $request->merge(['shortcut' => ltrim(mb_strtolower(trim((string) $request->input('shortcut'))), '/')]);

        /** @var array{shortcut: string, body: string} */
        return $request->validate([
            'shortcut' => [
                'required', 'string', 'max:40', 'regex:/^[\pL\pN_-]+$/u',
                Rule::unique('kinetix_quick_replies', 'shortcut')->where('team_id', $this->teamId())->ignore($reply?->getKey()),
            ],
            'body' => ['required', 'string', 'max:4096'],
        ]);
    }

    /**
     * @return Builder<QuickReply>
     */
    protected function scoped(): Builder
    {
        return QuickReply::query()->where('team_id', $this->teamId());
    }

    protected function find(Request $request): QuickReply
    {
        return $this->scoped()->whereKey($request->route('reply'))->firstOrFail();
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('channels');
    }
}
