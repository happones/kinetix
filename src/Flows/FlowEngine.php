<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Flows\Events\FlowRunFinished;
use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\ConversationMessage;
use Happones\Kinetix\Inbox\InboxManager;
use Happones\Kinetix\Inbox\MessageDirection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bots in conversations: a contact's message resumes the flow waiting for
 * it, or starts the first active flow whose trigger matches; delays resume
 * on the queue; a person answering pauses the bot. Runs keep the version
 * they started on, and every node they enter is counted.
 */
class FlowEngine
{
    public function __construct(
        protected InboxManager $inbox,
        protected FlowInterpreter $interpreter,
    ) {}

    /** A contact wrote: resume the flow waiting for them, or start one. */
    public function onMessage(Conversation $conversation, ConversationMessage $message): void
    {
        if ($message->direction !== MessageDirection::In) {
            return;
        }

        $run = $this->activeRun($conversation);

        if ($run !== null) {
            if ($run->status === FlowRunStatus::WaitingReply) {
                $this->advance($run, (string) ($message->body ?? ''), $message);
            }

            return;
        }

        // A conversation a person has taken stays with them.
        if (config('kinetix.flows.skip_assigned', true) && $conversation->assigned_to !== null) {
            return;
        }

        $flow = $this->match($conversation, $message);

        if ($flow !== null) {
            $this->start($flow, $conversation, $message);
        }
    }

    /** Start a flow in a conversation (a trigger matched, or someone started it from the inbox). */
    public function start(Flow $flow, Conversation $conversation, ?ConversationMessage $message = null): ?FlowRun
    {
        $version = $flow->publishedVersion;

        if ($version === null) {
            return null;
        }

        // One bot at a time in a conversation.
        $this->pause($conversation);

        $run = FlowRun::query()->create([
            'flow_id'         => $flow->getKey(),
            'flow_version_id' => $version->getKey(),
            'conversation_id' => $conversation->getKey(),
            'status'          => FlowRunStatus::Running,
            'current_node'    => null,
            'variables'       => $this->variables($conversation, $message),
        ]);

        $this->advance($run, null);

        return $run->refresh();
    }

    /** Resume runs whose wait is over (the queue usually does it on time; the scheduler catches up). */
    public function resumeDue(): int
    {
        $count = 0;

        FlowRun::query()
            ->where('status', FlowRunStatus::WaitingDelay->value)
            ->where('wait_until', '<=', now())
            ->lazyById()
            ->each(function (FlowRun $run) use (&$count): void {
                $this->resume($run);
                $count++;
            });

        return $count;
    }

    public function resume(FlowRun $run): void
    {
        if ($run->status === FlowRunStatus::WaitingDelay && ($run->wait_until === null || $run->wait_until->lte(now()))) {
            $this->advance($run, null);
        }
    }

    /** Step every bot of a conversation back (a person took over, another flow starts). */
    public function pause(Conversation $conversation): void
    {
        FlowRun::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('status', FlowRunStatus::activeValues())
            ->get()
            ->each(fn (FlowRun $run) => $this->finish($run, FlowRunStatus::Paused));
    }

    public function activeRun(Conversation $conversation): ?FlowRun
    {
        return FlowRun::query()
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('status', FlowRunStatus::activeValues())
            ->latest('id')
            ->first();
    }

    /**
     * The first active flow for the conversation's line whose trigger matches:
     * a keyword first, then a contact's first message, then any message.
     */
    public function match(Conversation $conversation, ConversationMessage $message): ?Flow
    {
        $flows = Flow::query()
            ->where('team_id', $conversation->team_id)
            ->where('status', 'active')
            ->whereNotNull('published_version_id')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Flow $flow): bool => $flow->runsOn($conversation->channel_id));

        $text    = mb_strtolower(trim((string) $message->body));
        $isFirst = ! $conversation->messages()->where('direction', MessageDirection::In->value)->whereKeyNot($message->getKey())->exists();

        foreach (['keyword', 'first_message', 'any_message'] as $type) {
            foreach ($flows as $flow) {
                if (($flow->trigger['type'] ?? null) !== $type) {
                    continue;
                }

                if ($type === 'any_message' || ($type === 'first_message' && $isFirst) || ($type === 'keyword' && $this->keywordMatches($flow, $text))) {
                    return $flow;
                }
            }
        }

        return null;
    }

    protected function keywordMatches(Flow $flow, string $text): bool
    {
        $match = (string) ($flow->trigger['match'] ?? 'contains');

        foreach ((array) ($flow->trigger['keywords'] ?? []) as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword === '') {
                continue;
            }

            $hit = match ($match) {
                'exact' => $text                                                          === $keyword,
                'regex' => @preg_match('/'.str_replace('/', '\/', $keyword).'/iu', $text) === 1,
                default => str_contains($text, $keyword),
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** Walk the run from where it stands and save where it got to. */
    protected function advance(FlowRun $run, ?string $input, ?ConversationMessage $message = null): void
    {
        $run->loadMissing(['flow', 'version', 'conversation.channel']);

        $state = new FlowState($run->current_node, $run->variables ?? [], (int) $run->attempts, $run->status, $run->wait_until);

        if ($message !== null) {
            $state->variables['message'] = $message->body;
        }

        $outbox  = new InboxOutbox($this->inbox, $run->conversation, $run->flow->name);
        $version = $run->flow_version_id;

        try {
            $this->interpreter->advance(new FlowGraph($run->version->graph), $state, $outbox, $input, entered: static function (string $node) use ($run, $version): void {
                DB::table('kinetix_flow_run_steps')->insert([
                    'flow_run_id'     => $run->getKey(),
                    'flow_version_id' => $version,
                    'node_id'         => $node,
                    'created_at'      => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Log::warning('Kinetix flows: a run failed.', ['run' => $run->getKey(), 'error' => $exception->getMessage()]);
            $state->status = FlowRunStatus::Failed;
            $state->error  = __('kinetix::kinetix.flow_error_unexpected');
        }

        $run->fill([
            'current_node' => $state->node,
            'variables'    => $state->variables,
            'attempts'     => $state->attempts,
            'wait_until'   => $state->waitUntil,
            'error'        => $state->error,
        ]);

        if ($state->status->isActive()) {
            $run->status = $state->status;
            $run->save();

            if ($state->status === FlowRunStatus::WaitingDelay && $state->waitUntil !== null) {
                ResumeFlowRun::dispatch($run->getKey())->delay($state->waitUntil);
            }

            return;
        }

        $this->finish($run, $state->status);
    }

    protected function finish(FlowRun $run, FlowRunStatus $status): void
    {
        $run->status      = $status;
        $run->finished_at = now();
        $run->wait_until  = null;
        $run->save();

        FlowRunFinished::dispatch($run);
    }

    /**
     * What a run starts knowing: who it's talking to, on which line, and what
     * they wrote.
     *
     * @return array<string, mixed>
     */
    protected function variables(Conversation $conversation, ?ConversationMessage $message): array
    {
        return [
            'contact' => ['name' => $conversation->contact_name ?? '', 'key' => $conversation->contact_key],
            'line'    => ['name' => $conversation->channel->name, 'provider' => $conversation->channel->provider],
            'message' => $message?->body,
        ];
    }
}
