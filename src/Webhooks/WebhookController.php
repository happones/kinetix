<?php

declare(strict_types=1);

namespace Happones\Kinetix\Webhooks;

use Happones\Kinetix\Data\WebhookEndpointData;
use Happones\Kinetix\Data\WebhookLogData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Customer-facing webhook management, gated by `webhooks.manage`. Endpoints are
 * team-scoped; secrets are only ever returned at creation / rotation; URLs are
 * SSRF-validated before they're stored.
 */
class WebhookController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        return response()->json([
            'endpoints' => $this->scopedEndpoints()
                ->latest()
                ->get()
                ->map(static fn (WebhookEndpoint $e): WebhookEndpointData => WebhookEndpointData::fromModel($e))
                ->values(),
            'events' => app(WebhookEventRegistry::class)->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $data   = $this->validatePayload($request);
        $secret = 'whsec_'.Str::random(40);

        $endpoint = WebhookEndpoint::create([
            'team_id' => $this->teamId(),
            'name'    => $data['name'],
            'url'     => $data['url'],
            'events'  => $data['events'],
            'active'  => $data['active'] ?? true,
            'secret'  => $secret,
        ]);

        // The secret is shown exactly once.
        return response()->json([
            'endpoint' => WebhookEndpointData::fromModel($endpoint),
            'secret'   => $secret,
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $endpoint = $this->findEndpoint($request);
        $data     = $this->validatePayload($request);

        $endpoint->update([
            'name'   => $data['name'],
            'url'    => $data['url'],
            'events' => $data['events'],
            'active' => $data['active'] ?? $endpoint->active,
        ]);

        return response()->json(WebhookEndpointData::fromModel($endpoint));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $this->findEndpoint($request)->delete();

        return response()->json(['status' => 'success']);
    }

    public function rotate(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $endpoint = $this->findEndpoint($request);
        $secret   = 'whsec_'.Str::random(40);

        $endpoint->update(['secret' => $secret]);

        return response()->json(['secret' => $secret]);
    }

    public function test(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $endpoint = $this->findEndpoint($request);

        DispatchWebhookJob::dispatch($endpoint->getKey(), 'webhook.test', [
            'message' => 'This is a test event from Kinetix.',
        ]);

        return response()->json(['status' => 'queued']);
    }

    public function logs(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $endpoint  = $this->findEndpoint($request);
        $paginator = $endpoint->logs()->latest()->paginate(15);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(static fn (WebhookLog $log): WebhookLogData => WebhookLogData::fromModel($log))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function redeliver(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $log = WebhookLog::query()
            ->whereKey($request->route('log'))
            ->whereIn('webhook_endpoint_id', $this->scopedEndpoints()->select('id'))
            ->firstOrFail();

        DispatchWebhookJob::dispatch($log->webhook_endpoint_id, $log->event, $log->payload ?? []);

        return response()->json(['status' => 'queued']);
    }

    /**
     * @return array{name: string, url: string, events: array<int, string>, active?: bool}
     */
    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'url'      => ['required', 'url', 'max:2048'],
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(app(WebhookEventRegistry::class)->all()))],
            'active'   => ['sometimes', 'boolean'],
        ]);

        abort_unless(WebhookUrlGuard::isAllowed($validated['url']), 422, 'The webhook URL is not allowed.');

        return $validated;
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    protected function scopedEndpoints()
    {
        return WebhookEndpoint::query()->where('team_id', $this->teamId());
    }

    protected function findEndpoint(Request $request): WebhookEndpoint
    {
        return $this->scopedEndpoints()->whereKey($request->route('endpoint'))->firstOrFail();
    }

    protected function teamId(): int|string|null
    {
        if (! config('kinetix.webhooks.teams', false)) {
            return null;
        }

        return auth()->user()?->currentTeam?->getKey();
    }
}
