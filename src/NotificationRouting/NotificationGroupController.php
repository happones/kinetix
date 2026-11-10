<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Data\NotificationAudienceData;
use Happones\Kinetix\Data\NotificationGroupData;
use Happones\Kinetix\NotificationRouting\Events\NotificationRouteChanged;
use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A team's notification groups: named sets of its members a rule can point
 * at. Only members of the current team can be put in a group, and every
 * group is looked up inside the current team (another team's is a 404).
 */
class NotificationGroupController
{
    public function __construct(
        protected AudienceResolver $resolver,
        protected RoutingAudiences $audiences,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $groups = NotificationGroup::query()->forCurrentTeam()->orderBy('name')->get();

        return response()->json(['groups' => $this->payloads($groups)]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $group = $this->guardedByName($request, function () use ($request): NotificationGroup {
            $data = $this->validated($request);

            return DB::transaction(function () use ($data): NotificationGroup {
                $group = NotificationGroup::query()->create([
                    ...NotificationGroup::teamAttributes(),
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? null,
                ]);

                $this->sync($group, $this->people($data['member_ids'] ?? []));

                return $group;
            });
        });

        $this->changed('created', $group, $request, null, ['members' => $this->refsOf($group)]);

        return response()->json(['group' => $this->payloads(collect([$group]))[0]], 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $group = $this->find($request);
        $name  = $group->name;
        $diff  = ['added' => [], 'removed' => []];

        $this->guardedByName($request, function () use ($request, $group, &$diff): void {
            $data = $this->validated($request, $group);

            DB::transaction(function () use ($group, $data, &$diff): void {
                $group->update(array_intersect_key($data, array_flip(['name', 'description'])));

                if (array_key_exists('member_ids', $data)) {
                    $diff = $this->sync($group, $this->people($data['member_ids']));
                }
            });
        });

        $this->changed('updated', $group, $request, ['name' => $name, 'members' => $diff['removed']], ['members' => $diff['added']]);

        return response()->json(['group' => $this->payloads(collect([$group]))[0]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $group   = $this->find($request);
        $members = $this->refsOf($group);

        // The rules that pointed at it go with it.
        DB::transaction(function () use ($group): void {
            NotificationRoute::query()->forCurrentTeam()
                ->where('audience_type', AudienceType::Group->value)->where('audience_id', (string) $group->getKey())
                ->delete();

            $group->members()->delete();
            $group->delete();
        });

        Memo::flush(AudienceResolver::MEMO);

        $this->changed('deleted', $group, $request, ['name' => $group->name, 'members' => $members], null);

        return response()->json(['status' => 'success']);
    }

    /**
     * Add members to a group (the ones already in it stay).
     */
    public function addMembers(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $group = $this->find($request);
        $data  = $request->validate([
            'member_ids'   => ['required', 'array', 'min:1', 'max:1000'],
            'member_ids.*' => ['required', 'string', 'distinct'],
        ]);

        $added = DB::transaction(fn (): array => $this->attach($group, $this->people($data['member_ids'])));

        $this->changed('updated', $group, $request, null, ['members' => $added]);

        return response()->json(['group' => $this->payloads(collect([$group]))[0]]);
    }

    /**
     * Take one member (by member ref) out of a group.
     */
    public function removeMember(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $group  = $this->find($request);
        $ref    = (string) $request->route('member');
        $parsed = RoutingPeople::parse($ref);

        $removed = 0;

        if ($parsed !== null && RoutingLock::plausibleKey($parsed[1], $this->userKeyType())) {
            $removed = NotificationGroupMember::query()->where('group_id', $group->getKey())
                ->where('user_id', $parsed[1])->whereIn('user_type', $this->types($parsed[0]))->delete();
        }

        if ($removed > 0) {
            $this->changed('updated', $group, $request, ['members' => [$ref]], null);
        }

        return response()->json(['group' => $this->payloads(collect([$group]))[0]]);
    }

    /**
     * Run a group write holding a lock on its name, so two requests at once
     * can't both pass the uniqueness check (with no teams the key is NULL and
     * the database can't enforce it).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $write
     * @return TReturn
     */
    protected function guardedByName(Request $request, callable $write): mixed
    {
        $name = is_string($request->input('name')) ? mb_strtolower(trim($request->input('name'))) : '';

        try {
            return RoutingLock::run('kinetix.notification-routing.group.'.md5((string) NotificationGroup::currentTeamId().'|'.$name), $write);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => 'name'])]);
        }
    }

    /**
     * @return array{name: string, description?: string|null, member_ids?: array<int, string>}
     */
    protected function validated(Request $request, ?NotificationGroup $group = null): array
    {
        $required = $group === null ? 'required' : 'sometimes';

        /** @var array{name: string, description?: string|null, member_ids?: array<int, string>} */
        return $request->validate([
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('kinetix_notification_groups', 'name')
                    ->where(fn ($query) => $query->where('team_id', $this->resolver->scopeKey($this->audiences->team())))
                    ->ignore($group?->getKey()),
            ],
            'description'  => ['nullable', 'string', 'max:255'],
            'member_ids'   => ['sometimes', 'array', 'max:1000'],
            'member_ids.*' => ['required', 'string', 'distinct'],
        ]);
    }

    /**
     * The team members the refs name: a ref that is not a member of the
     * current team is a 404, so nobody outside it can be put in a group.
     *
     * @param  array<int, mixed>      $refs
     * @return Collection<int, Model>
     */
    protected function people(array $refs): Collection
    {
        $refs  = array_values(array_unique(array_filter($refs, 'is_string')));
        $found = $this->audiences->people()->find($refs);

        abort_if(count($found) !== count($refs), 404);

        return collect(array_values($found));
    }

    /**
     * The type of the group members' `user_id` column (`HostKeys::user`).
     */
    protected function userKeyType(): string
    {
        return HostKeys::type('user') === 'bigint' ? 'int' : 'string';
    }

    /**
     * The model types a ref's type may be stored as (its morph alias, or the
     * class name written before a morph map).
     *
     * @return list<string>
     */
    protected function types(string $morph): array
    {
        $class = Relation::getMorphedModel($morph);

        return array_values(array_unique(array_filter([$morph, $class])));
    }

    /**
     * Make the group hold exactly these people, in a few queries whatever
     * their number.
     *
     * @param  Collection<int, Model>                            $people
     * @return array{added: list<string>, removed: list<string>}
     */
    protected function sync(NotificationGroup $group, Collection $people): array
    {
        $keep     = $people->mapWithKeys(static fn (Model $user): array => [RoutingPeople::ref($user) => $user]);
        $existing = $group->members()->get();
        $drop     = $existing->reject(static fn (NotificationGroupMember $member): bool => $keep->has($member->user_type.'|'.$member->user_id));

        if ($drop->isNotEmpty()) {
            NotificationGroupMember::query()->whereKey($drop->modelKeys())->delete();
        }

        return ['added' => $this->attach($group, $people, $existing), 'removed' => $drop->map(static fn (NotificationGroupMember $member): string => $member->user_type.'|'.$member->user_id)->values()->all()];
    }

    /**
     * Add the people who are not in the group yet, in one insert.
     *
     * @param  Collection<int, Model>                        $people
     * @param  Collection<int, NotificationGroupMember>|null $existing
     * @return list<string>                                  the refs added
     */
    protected function attach(NotificationGroup $group, Collection $people, ?Collection $existing = null): array
    {
        $existing ??= $group->members()->get();
        $held  = $existing->mapWithKeys(static fn (NotificationGroupMember $member): array => [$member->user_type.'|'.$member->user_id => true]);
        $now   = now();
        $rows  = [];
        $added = [];

        foreach ($people as $user) {
            $ref = RoutingPeople::ref($user);

            if ($held->has($ref)) {
                continue;
            }

            $rows[]  = ['group_id' => $group->getKey(), 'user_type' => $user->getMorphClass(), 'user_id' => $user->getKey(), 'created_at' => $now, 'updated_at' => $now];
            $added[] = $ref;
        }

        if ($rows !== []) {
            NotificationGroupMember::query()->insertOrIgnore($rows);
        }

        return $added;
    }

    /**
     * @return list<string>
     */
    protected function refsOf(NotificationGroup $group): array
    {
        return $group->members()->get()->map(static fn (NotificationGroupMember $member): string => $member->user_type.'|'.$member->user_id)->values()->all();
    }

    /**
     * @param  Collection<int, NotificationGroup> $groups
     * @return array<int, NotificationGroupData>
     */
    protected function payloads(Collection $groups): array
    {
        $held = $groups->isEmpty() ? new Collection : NotificationGroupMember::query()->whereIn('group_id', $groups->map(static fn (NotificationGroup $group): int|string => $group->getKey())->all())->get()->groupBy('group_id');

        $members = $this->audiences->people()->find($held->flatten(1)->map(static fn (NotificationGroupMember $member): string => $member->user_type.'|'.$member->user_id)->unique()->values()->all());

        return $groups->map(function (NotificationGroup $group) use ($held, $members): NotificationGroupData {
            $people = ($held[$group->getKey()] ?? new Collection)
                ->map(static fn (NotificationGroupMember $member): ?Model => $members[$member->user_type.'|'.$member->user_id] ?? null)
                ->filter()
                ->map(fn (Model $user): NotificationAudienceData => new NotificationAudienceData(RoutingPeople::ref($user), $this->audiences->name($user)))
                ->values()
                ->all();

            return new NotificationGroupData($group->getKey(), $group->name, $group->description, $people);
        })->values()->all();
    }

    protected function find(Request $request): NotificationGroup
    {
        $id = (string) $request->route('group');

        abort_unless(RoutingLock::plausibleKey($id), 404);

        return NotificationGroup::query()->forCurrentTeam()->whereKey($id)->firstOrFail();
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    protected function changed(string $action, NotificationGroup $group, Request $request, ?array $before, ?array $after): void
    {
        NotificationRouteChanged::dispatch($action, 'group', $group->team_id, $request->user()?->getAuthIdentifier(), $before, $after === null ? null : ['name' => $group->name, ...$after]);
    }
}
