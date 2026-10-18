<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\InboxManager;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Bots from the inbox, for whoever sees the conversation: which flows can be
 * started in it (and which one is running), start one, stop it.
 */
class FlowInboxController
{
    public function __construct(
        protected InboxManager $inbox,
        protected FlowEngine $engine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $conversation = $this->find($request);

        return response()->json([
            'flows' => $this->startable($conversation)->map(static fn (Flow $flow): array => ['id' => $flow->getKey(), 'name' => $flow->name])->values(),
            'run'   => $this->present($this->engine->activeRun($conversation)),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $conversation = $this->find($request);
        $flow         = $this->startable($conversation)->firstWhere('id', $request->route('flow'));

        abort_if($flow === null, 404);

        return response()->json(['run' => $this->present($this->engine->start($flow, $conversation))]);
    }

    public function stop(Request $request): JsonResponse
    {
        $this->engine->pause($this->find($request));

        return response()->json(['run' => null]);
    }

    /**
     * Active flows that run on the conversation's line.
     *
     * @return Collection<int, Flow>
     */
    protected function startable(Conversation $conversation)
    {
        return Flow::query()
            ->where('team_id', $conversation->team_id)
            ->where('status', 'active')
            ->whereNotNull('published_version_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (Flow $flow): bool => $flow->runsOn($conversation->channel_id))
            ->values();
    }

    /**
     * @return array{id: int|string, flow: string, status: string}|null
     */
    protected function present(?FlowRun $run): ?array
    {
        if ($run === null || ! $run->status->isActive()) {
            return null;
        }

        return ['id' => $run->getKey(), 'flow' => $run->flow->name, 'status' => $run->status->value];
    }

    protected function find(Request $request): Conversation
    {
        Gate::authorize('conversations.view');

        $user = auth()->user();
        abort_unless($user instanceof Model, 403);

        return $this->inbox->conversationsFor($user, KinetixTeams::keyFor('channels'))
            ->whereKey($request->route('conversation'))
            ->firstOrFail();
    }
}
