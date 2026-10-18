<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Inbox\InboxManager;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The team's bot flows, gated by `flows.manage`: list, create, edit the
 * draft (graph, trigger, lines), publish it as a new version, turn the flow
 * on and off, duplicate, delete, test it in the simulator, and see how many
 * conversations went through each node.
 */
class FlowController
{
    public function __construct(
        protected FlowGraphValidator $validator,
        protected FlowSimulator $simulator,
        protected NodeRegistry $nodes,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('flows.manage');

        $flows = $this->scoped()->with('publishedVersion')->orderByDesc('priority')->orderBy('name')->get();
        $runs  = FlowRun::query()
            ->whereIn('flow_id', $flows->modelKeys())
            ->selectRaw('flow_id, count(*) as total, sum(case when status in (?, ?, ?) then 1 else 0 end) as active', FlowRunStatus::activeValues())
            ->groupBy('flow_id')
            ->get()
            ->keyBy('flow_id');

        return response()->json([
            'flows' => $flows->map(fn (Flow $flow): array => $this->summary($flow, (int) ($runs->get($flow->getKey())->total ?? 0), (int) ($runs->get($flow->getKey())->active ?? 0)))->values(),
            'lines' => $this->lines(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('flows.manage');

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $flow = Flow::query()->create([
            'team_id' => $this->teamId(),
            'name'    => $data['name'],
            'status'  => 'draft',
            'trigger' => ['type' => 'keyword', 'keywords' => [], 'match' => 'contains'],
            'draft'   => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start', 'position' => ['x' => 40, 'y' => 160], 'data' => []],
                    ['id' => 'welcome', 'type' => 'message', 'position' => ['x' => 340, 'y' => 140], 'data' => ['text' => __('kinetix::kinetix.flow_default_welcome')]],
                ],
                'edges' => [
                    ['id' => 'e-start-welcome', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'welcome'],
                ],
            ],
        ]);

        return response()->json(['flow' => $this->detail($flow)], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['flow' => $this->detail($this->find($request))]);
    }

    /** Save the draft: graph, name, trigger, lines. Nothing changes for conversations until it's published. */
    public function update(Request $request): JsonResponse
    {
        $flow = $this->find($request);
        $data = $request->validate([
            'name'               => ['sometimes', 'string', 'max:100'],
            'graph'              => ['sometimes', 'array'],
            'graph.nodes'        => ['required_with:graph', 'array', 'max:200'],
            'graph.edges'        => ['present_with:graph', 'array', 'max:500'],
            'trigger'            => ['sometimes', 'array'],
            'trigger.type'       => ['required_with:trigger', Rule::in(['keyword', 'first_message', 'any_message', 'manual'])],
            'trigger.keywords'   => ['nullable', 'array', 'max:50'],
            'trigger.keywords.*' => ['string', 'max:100'],
            'trigger.match'      => ['nullable', Rule::in(['contains', 'exact', 'regex'])],
            'channel_ids'        => ['nullable', 'array'],
            'priority'           => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $flow->fill(array_filter([
            'name'     => $data['name'] ?? null,
            'draft'    => isset($data['graph']) ? $this->cleanGraph($data['graph']) : null,
            'trigger'  => $data['trigger']  ?? null,
            'priority' => $data['priority'] ?? null,
        ], static fn ($value): bool => $value !== null));

        if (array_key_exists('channel_ids', $data)) {
            $allowed           = Channel::query()->where('team_id', $this->teamId())->pluck('id')->map('strval')->all();
            $flow->channel_ids = $data['channel_ids'] === null || $data['channel_ids'] === []
                ? null
                : array_values(array_filter($data['channel_ids'], static fn ($id): bool => in_array((string) $id, $allowed, true)));
        }

        $flow->save();

        return response()->json(['flow' => $this->detail($flow)]);
    }

    /** Freeze the draft as a new version and turn the flow on; new conversations run it, running ones finish theirs. */
    public function publish(Request $request): JsonResponse
    {
        $flow     = $this->find($request);
        $problems = $this->validator->problems($flow->draft);

        if ($problems !== []) {
            return response()->json(['message' => __('kinetix::kinetix.flow_publish_blocked'), 'problems' => $problems], 422);
        }

        DB::transaction(function () use ($flow, $request): void {
            $version = $flow->versions()->create([
                'number'       => ((int) $flow->versions()->max('number')) + 1,
                'graph'        => $flow->draft,
                'published_by' => $request->user()?->getAuthIdentifier(),
            ]);

            $flow->update(['published_version_id' => $version->getKey(), 'status' => 'active']);
        });

        return response()->json(['flow' => $this->detail($flow->refresh())]);
    }

    public function activate(Request $request): JsonResponse
    {
        $flow = $this->find($request);

        if ($flow->published_version_id === null) {
            throw ValidationException::withMessages(['status' => __('kinetix::kinetix.flow_error_unpublished')]);
        }

        $flow->update(['status' => 'active']);

        return response()->json(['flow' => $this->detail($flow)]);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $flow = $this->find($request);
        $flow->update(['status' => 'inactive']);

        return response()->json(['flow' => $this->detail($flow)]);
    }

    public function duplicate(Request $request): JsonResponse
    {
        $flow = $this->find($request);
        $copy = $flow->replicate(['published_version_id']);

        $copy->name   = __('kinetix::kinetix.flow_copy_of', ['name' => $flow->name]);
        $copy->status = 'draft';
        $copy->save();

        return response()->json(['flow' => $this->detail($copy)], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->find($request)->delete();

        return response()->json(['status' => 'success']);
    }

    /** Run the (unsaved) graph in the test chat: nothing is sent. */
    public function simulate(Request $request): JsonResponse
    {
        $this->find($request);

        $data = $request->validate([
            'graph'        => ['required', 'array'],
            'state'        => ['nullable', 'array'],
            'input'        => ['nullable', 'string', 'max:4096'],
            'contact_name' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->simulator->run($this->cleanGraph($data['graph']), $data['state'] ?? null, $data['input'] ?? null, (string) ($data['contact_name'] ?? '')));
    }

    /**
     * How a version did, node by node: conversations that reached it, the
     * ones waiting there now, and the ones that stopped there.
     */
    public function stats(Request $request): JsonResponse
    {
        $flow    = $this->find($request);
        $version = $request->query('version') ? $flow->versions()->whereKey($request->query('version'))->first() : $flow->publishedVersion;

        if ($version === null) {
            return response()->json(['version' => null, 'nodes' => (object) [], 'runs' => 0]);
        }

        $entered = DB::table('kinetix_flow_run_steps')
            ->where('flow_version_id', $version->getKey())
            ->selectRaw('node_id, count(distinct flow_run_id) as total')
            ->groupBy('node_id')
            ->pluck('total', 'node_id');

        $here = FlowRun::query()
            ->where('flow_version_id', $version->getKey())
            ->whereNotNull('current_node')
            ->selectRaw('current_node, status, count(*) as total')
            ->groupBy('current_node', 'status')
            ->get();

        $nodes = [];

        foreach ($entered as $node => $total) {
            $nodes[$node] = ['entered' => (int) $total, 'waiting' => 0, 'stopped' => 0, 'completed' => 0];
        }

        foreach ($here as $row) {
            $node = (string) $row->getAttribute('current_node');
            $nodes[$node] ??= ['entered' => 0, 'waiting' => 0, 'stopped' => 0, 'completed' => 0];
            $raw    = $row->getAttribute('status');
            $status = $raw instanceof FlowRunStatus ? $raw : FlowRunStatus::from((string) $raw);
            $bucket = match (true) {
                $status->isActive()                  => 'waiting',
                $status === FlowRunStatus::Completed => 'completed',
                default                              => 'stopped',
            };
            $nodes[$node][$bucket] += (int) $row->getAttribute('total');
        }

        return response()->json([
            'version' => ['id' => $version->getKey(), 'number' => $version->number],
            'nodes'   => (object) $nodes,
            'runs'    => FlowRun::query()->where('flow_version_id', $version->getKey())->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Flow $flow, int $runs = 0, int $active = 0): array
    {
        return [
            'id'          => $flow->getKey(),
            'name'        => $flow->name,
            'status'      => $flow->status,
            'trigger'     => $flow->trigger,
            'channelIds'  => $flow->channel_ids,
            'priority'    => $flow->priority,
            'version'     => $flow->publishedVersion?->number,
            'unpublished' => $flow->hasUnpublishedChanges(),
            'runs'        => $runs,
            'activeRuns'  => $active,
            'updatedAt'   => $flow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Flow $flow): array
    {
        $flow->loadMissing('publishedVersion');

        return [
            ...$this->summary($flow),
            'graph'    => $flow->draft,
            'problems' => $this->validator->problems($flow->draft),
            'versions' => $flow->versions()->orderByDesc('number')->limit(20)->get(['id', 'number', 'created_at'])
                ->map(static fn (FlowVersion $version): array => ['id' => $version->getKey(), 'number' => $version->number, 'createdAt' => $version->created_at?->toIso8601String()])
                ->values(),
            'nodeTypes' => $this->nodes->types(),
            'lines'     => $this->lines(),
            // Whom a handoff can assign to.
            'members' => app(InboxManager::class)->members($this->teamId())
                ->map(static fn (Model $user): array => ['id' => $user->getKey(), 'name' => app(InboxManager::class)->userName($user)])
                ->values(),
        ];
    }

    /**
     * @return list<array{id: int|string, name: string, provider: string}>
     */
    protected function lines(): array
    {
        return Channel::query()->where('team_id', $this->teamId())->orderBy('name')->get()
            ->map(static fn (Channel $line): array => ['id' => $line->getKey(), 'name' => $line->name, 'provider' => $line->provider])
            ->values()
            ->all();
    }

    /**
     * Keep only what a graph is made of: nodes {id, type, position, data},
     * edges {id, source, sourceHandle, target}.
     *
     * @param  array<string, mixed>                                                        $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    protected function cleanGraph(array $graph): array
    {
        $nodes = [];

        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && is_string($node['type'] ?? null)) {
                $nodes[] = [
                    'id'       => mb_substr($node['id'], 0, 64),
                    'type'     => mb_substr($node['type'], 0, 32),
                    'position' => ['x' => (float) data_get($node, 'position.x', 0), 'y' => (float) data_get($node, 'position.y', 0)],
                    'data'     => is_array($node['data'] ?? null) ? $node['data'] : [],
                ];
            }
        }

        $edges = [];

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (is_array($edge) && is_string($edge['source'] ?? null) && is_string($edge['target'] ?? null)) {
                $edges[] = [
                    'id'           => (string) ($edge['id'] ?? $edge['source'].'-'.$edge['target']),
                    'source'       => $edge['source'],
                    'sourceHandle' => is_string($edge['sourceHandle'] ?? null) ? $edge['sourceHandle'] : 'next',
                    'target'       => $edge['target'],
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return Builder<Flow>
     */
    protected function scoped(): Builder
    {
        return Flow::query()->where('team_id', $this->teamId());
    }

    protected function find(Request $request): Flow
    {
        Gate::authorize('flows.manage');

        return $this->scoped()->whereKey($request->route('flow'))->firstOrFail();
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('channels');
    }
}
