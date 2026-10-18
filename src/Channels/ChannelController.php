<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Data\ChannelData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The team's lines, gated by `channels.manage`: add one (provisioning it with
 * its provider), link it by QR, pairing code or token, follow its status,
 * unlink, restart, send a test message, rename and delete. Lines are
 * team-scoped; credentials and webhook secrets never leave the server.
 */
class ChannelController
{
    public function __construct(protected ChannelManager $manager) {}

    public function index(): JsonResponse
    {
        Gate::authorize('channels.manage');

        return response()->json([
            'channels'  => $this->scoped()->oldest()->get()->map(static fn (Channel $c): ChannelData => ChannelData::fromModel($c))->values(),
            'providers' => $this->manager->providers(),
            'limit'     => $this->manager->limit($this->teamId()),
            'broadcast' => $this->manager->broadcastChannel($this->teamId()),
            // With the inbox on, each line has its agents.
            'agents' => config('kinetix.inbox.enabled', false) === true,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $provider = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys($this->manager->available()))],
        ])['provider'];

        $driver = $this->manager->driver($provider);
        $data   = $request->validate(['name' => ['required', 'string', 'max:100'], ...$driver->rules()]);
        $limit  = $this->manager->limit($this->teamId());

        if ($limit !== null && $this->scoped()->count() >= $limit) {
            throw ValidationException::withMessages(['name' => __('kinetix::kinetix.channel_limit_reached', ['count' => $limit])]);
        }

        $channel = new Channel([
            'team_id'        => $this->teamId(),
            'provider'       => $provider,
            'name'           => $data['name'],
            'public_id'      => (string) Str::ulid(),
            'webhook_secret' => Str::random(48),
            'status'         => ChannelStatus::Disconnected,
        ]);

        try {
            $driver->provision($channel, Arr::except($data, ['name']), $this->manager->webhookUrl($channel));
        } catch (ChannelException $exception) {
            throw ValidationException::withMessages([$exception->field ?? 'provider' => $exception->getMessage()]);
        }

        $channel->save();

        // A line linked by QR shows its first code right away.
        $link = null;

        if ($channel->status === ChannelStatus::Connecting && in_array('qr', $driver->linkMethods(), true)) {
            $link = rescue(fn (): array => $driver->link($channel)->toArray(), null, report: false);
        }

        return response()->json(['channel' => ChannelData::fromModel($channel), 'link' => $link], 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);
        $channel->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return response()->json(['channel' => ChannelData::fromModel($channel)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);

        // A provider that's down or already forgot the line doesn't keep it
        // in the list.
        try {
            $this->manager->for($channel)->remove($channel);
        } catch (Throwable $exception) {
            Log::warning('Kinetix channels: removing the line at its provider failed.', ['channel' => $channel->getKey(), 'error' => $exception->getMessage()]);
        }

        $channel->delete();

        return response()->json(['status' => 'success']);
    }

    /** A fresh QR — or, with `?phone=`, a pairing code — for a line waiting to be linked. */
    public function link(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);
        $phone   = $request->validate(['phone' => ['nullable', 'string', 'regex:/^\+?[\d\s()-]{8,20}$/']])['phone'] ?? null;

        return $this->attempt($channel, function () use ($channel, $phone): JsonResponse {
            $state = $this->manager->for($channel)->link($channel, $phone);
            $this->manager->transition($channel, $state->status);

            return response()->json(['channel' => ChannelData::fromModel($channel), 'link' => $state->toArray()]);
        });
    }

    /** Where the line stands — what the linker polls (checked against the provider every few seconds). */
    public function status(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        return response()->json(['channel' => ChannelData::fromModel($this->manager->sync($this->find($request)))]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);

        return $this->attempt($channel, function () use ($channel): JsonResponse {
            $this->manager->for($channel)->disconnect($channel);
            $this->manager->transition($channel, ChannelStatus::Disconnected);

            return response()->json(['channel' => ChannelData::fromModel($channel)]);
        });
    }

    public function restart(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);

        return $this->attempt($channel, function () use ($channel): JsonResponse {
            $this->manager->for($channel)->restart($channel);

            return response()->json(['channel' => ChannelData::fromModel($this->manager->sync($channel, force: true))]);
        });
    }

    /** Send a test message from the line, to check it end to end. */
    public function test(Request $request): JsonResponse
    {
        Gate::authorize('channels.manage');

        $channel = $this->find($request);
        $data    = $request->validate([
            'to'   => ['required', 'string', 'max:64'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        return $this->attempt($channel, function () use ($channel, $data): JsonResponse {
            $this->manager->sendText($channel, $data['to'], $data['text']);

            return response()->json(['status' => 'sent']);
        });
    }

    /**
     * Run a provider call: a refusal (a revoked token, a line the server
     * forgot) is a 422 with its message; an unreachable provider a 502.
     *
     * @param callable(): JsonResponse $call
     */
    protected function attempt(Channel $channel, callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ChannelException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::warning('Kinetix channels: provider call failed.', ['channel' => $channel->getKey(), 'error' => $exception->getMessage()]);

            return response()->json(['message' => __('kinetix::kinetix.channel_error_unreachable')], 502);
        }
    }

    /**
     * @return Builder<Channel>
     */
    protected function scoped(): Builder
    {
        return Channel::query()->where('team_id', $this->teamId());
    }

    protected function find(Request $request): Channel
    {
        return $this->scoped()->whereKey($request->route('channel'))->firstOrFail();
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('channels');
    }
}
