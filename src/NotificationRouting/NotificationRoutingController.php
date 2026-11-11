<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Data\NotificationAudienceData;
use Happones\Kinetix\Data\NotificationRecipientData;
use Happones\Kinetix\Data\NotificationRecipientsData;
use Happones\Kinetix\Data\NotificationRouteData;
use Happones\Kinetix\Data\NotificationRoutingTypeData;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\NotificationRouting\Events\NotificationRouteChanged;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A team's notification routing: which types it routes, to which members,
 * roles and groups, on which channels. Everything asks for
 * `notification_routing.manage`; every id is looked up inside the current
 * team, so another team's rule, group, role or member is a 404.
 */
class NotificationRoutingController
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected NotificationPreferenceManager $preferences,
        protected RoutingAudiences $audiences,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $all    = NotificationRoute::query()->forCurrentTeam()->orderBy('id')->get();
        $labels = $this->labels($all);
        $routes = $all->groupBy('type');

        $types = [];

        foreach ($this->routable() as $key => $label) {
            $types[] = new NotificationRoutingTypeData(
                $key,
                $label,
                $this->preferences->channelsOf($key),
                array_values(array_intersect($this->registry->mandatoryOf($key), $this->preferences->channelsOf($key))),
                $this->registry->requirementOf($key),
                ($routes[$key] ?? new Collection)->map(fn (NotificationRoute $route): NotificationRouteData => $this->payload($route, $labels))->values()->all(),
                $this->fallbackDescription($key),
                $this->registry->hasFallback($key),
            );
        }

        return response()->json([
            'types'    => $types,
            'channels' => array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                array_keys($this->preferences->channels()),
                array_values($this->preferences->channels()),
            ),
        ]);
    }

    /**
     * What a rule can point at. Members come as one page of 200, by name and
     * narrowed by `?search=`; `membersComplete` says that page is every match.
     * `?members=0` leaves the members out (the roles and groups still come).
     */
    public function audiences(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $search  = $request->query('search');
        $members = new Collection;

        if ($request->query('members') !== '0') {
            $members = $this->audiences->members(is_string($search) ? $search : null);
        }

        $complete = $request->query('members') !== '0' && $members->count() <= RoutingPeople::PAGE;

        return response()->json([
            'members'         => $members->take(RoutingPeople::PAGE)->map(fn (Model $user): NotificationAudienceData => new NotificationAudienceData(RoutingPeople::ref($user), $this->audiences->name($user)))->values(),
            'membersComplete' => $complete,
            'roles'           => $this->audiences->roles()->map(static fn (string $name, string $id): NotificationAudienceData => new NotificationAudienceData($id, $name))->values(),
            'groups'          => $this->audiences->groups()->map(static fn (string $name, string $id): NotificationAudienceData => new NotificationAudienceData($id, $name))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        // Any scalar id is accepted (a role's integer id, say).
        if (is_scalar($request->input('audience_id'))) {
            $request->merge(['audience_id' => (string) $request->input('audience_id')]);
        }

        $type = $request->input('type');
        $data = $request->validate([
            'type'          => ['required', 'string', Rule::in(array_keys($this->routable()))],
            'audience_type' => ['required', 'string', Rule::enum(AudienceType::class)],
            'audience_id'   => ['nullable', 'string', 'max:191'],
            'channels'      => ['required', 'array', 'min:1'],
            'channels.*'    => ['required', 'string', 'distinct', Rule::in(is_string($type) ? $this->preferences->channelsOf($type) : [])],
        ]);

        $audience = AudienceType::from($data['audience_type']);
        $id       = $this->audienceId($audience, $data['audience_id'] ?? null);
        $channels = array_values($data['channels']);

        [$route, $before] = $this->upsert($data['type'], $audience, $id, $channels);

        $created = $before === null;

        $this->changed($created ? 'created' : 'updated', $route, $request, $before === null ? null : ['channels' => $before]);

        return response()->json(['route' => $this->payload($route, $this->labels(collect([$route])))], $created ? 201 : 200);
    }

    /**
     * Create the rule, or replace the channels of the one already there. A
     * lock covers the check-then-write (the unique key can't while the team
     * is NULL, with no teams), and a violation from a writer that got in
     * anyway becomes an update.
     *
     * @param  list<string>                                      $channels
     * @return array{0: NotificationRoute, 1: list<string>|null} the rule and the channels it had (null when new)
     */
    protected function upsert(string $type, AudienceType $audience, string $id, array $channels): array
    {
        $write = function () use ($type, $audience, $id, $channels): array {
            $find = static fn (): ?NotificationRoute => NotificationRoute::query()->forCurrentTeam()
                ->where('type', $type)->where('audience_type', $audience->value)->where('audience_id', $id)->first();

            $route = $find();

            if ($route === null) {
                try {
                    return [NotificationRoute::query()->create([
                        ...NotificationRoute::teamAttributes(),
                        'type'          => $type,
                        'audience_type' => $audience,
                        'audience_id'   => $id,
                        'channels'      => $channels,
                    ]), null];
                } catch (UniqueConstraintViolationException) {
                    $route = $find();

                    abort_if($route === null, 409);
                }
            }

            $before = (array) $route->channels;
            $route->update(['channels' => $channels]);

            return [$route, $before];
        };

        /** @var array{0: NotificationRoute, 1: list<string>|null} */
        return RoutingLock::run('kinetix.notification-routing.route.'.md5(implode('|', [(string) NotificationRoute::currentTeamId(), $type, $audience->value, $id])), $write);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $route = $this->find($request);

        // A type that is no longer offered to the team can't be edited.
        abort_unless(array_key_exists($route->type, $this->routable()), 404);

        $data = $request->validate([
            'channels'   => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', 'distinct', Rule::in($this->preferences->channelsOf($route->type))],
        ]);

        $before = $route->channels;
        $route->update(['channels' => array_values($data['channels'])]);

        $this->changed('updated', $route, $request, ['channels' => $before]);

        return response()->json(['route' => $this->payload($route, $this->labels(collect([$route])))]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $route = $this->find($request);
        $route->delete();

        $this->changed('deleted', $route, $request, ['channels' => $route->channels]);

        return response()->json(['status' => 'success']);
    }

    /**
     * The dry run: who a notification of the type would reach now, on which
     * channels, and who is left out and why. Nothing is sent.
     */
    public function recipients(Request $request): JsonResponse
    {
        Gate::authorize('notification_routing.manage');

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys($this->routable()))],
        ]);

        $plan = KinetixNotificationRouting::for($data['type'], $this->audiences->team())->recipients();

        return response()->json(new NotificationRecipientsData(
            array_map(fn (PlannedRecipient $recipient): NotificationRecipientData => new NotificationRecipientData(RoutingPeople::ref($recipient->user), $this->audiences->name($recipient->user), $recipient->channels, null), $plan->recipients),
            array_map(fn (SkippedRecipient $skipped): NotificationRecipientData => new NotificationRecipientData(RoutingPeople::ref($skipped->user), $this->audiences->name($skipped->user), [], $skipped->reason->value), $plan->skipped),
            $plan->skippedCount,
            $plan->hasRules,
            $plan->truncated,
            $plan->fellBack,
        ));
    }

    /**
     * The types the team routes: registered, routable and offered to it (the
     * host's availability callback). Kinetix's own types are not routable by
     * default: they go to the person who started the export or import. Worked
     * out once per request.
     *
     * @return array<string, string>
     */
    protected function routable(): array
    {
        $team = $this->audiences->team();

        /** @var array<string, string> */
        return Memo::remember(
            AudienceResolver::MEMO,
            request(),
            'routable:'.($team ?? '-'),
            fn (): array => array_filter(
                $this->registry->all(),
                fn (string $label, string $key): bool => $this->registry->isAvailable($key, $team),
                ARRAY_FILTER_USE_BOTH,
            ),
        );
    }

    /**
     * Who gets the type while the team has chosen nobody, when the host said
     * so; translated for this request.
     */
    protected function fallbackDescription(string $type): ?string
    {
        $description = $this->registry->fallbackDescriptionOf($type);

        return $description === null ? null : (string) __($description);
    }

    /**
     * The audience key a rule stores, after checking the audience exists in
     * the current team (a 404 otherwise). The eligible audience has none.
     */
    protected function audienceId(AudienceType $audience, ?string $id): string
    {
        if ($audience === AudienceType::Eligible) {
            return '';
        }

        if ($id === null || $id === '') {
            throw ValidationException::withMessages(['audience_id' => __('kinetix::kinetix.notification_routing_audience_required')]);
        }

        $exists = match ($audience) {
            AudienceType::Member => $this->audiences->member($id) !== null,
            AudienceType::Role   => $this->audiences->roles()->has($id),
            AudienceType::Group  => RoutingLock::plausibleKey($id) && NotificationGroup::query()->forCurrentTeam()->whereKey($id)->exists(),
        };

        abort_unless($exists, 404);

        return $id;
    }

    /**
     * @param array<string, string> $labels "type|id" => name, from {@see labels()}
     */
    protected function payload(NotificationRoute $route, array $labels): NotificationRouteData
    {
        $id = $route->audience_id === '' ? null : $route->audience_id;

        return new NotificationRouteData(
            $route->getKey(),
            $route->type,
            $route->audience_type->value,
            $id,
            $id === null ? null : ($labels[$route->audience_type->value.'|'.$id] ?? null),
            $route->channels,
        );
    }

    /**
     * The names of what the given rules point at, "audience type|id" => name.
     * Members are looked up by their refs only.
     *
     * @param  Collection<int, NotificationRoute> $routes
     * @return array<string, string>
     */
    protected function labels(Collection $routes): array
    {
        $labels = [];
        $refs   = $routes->where('audience_type', AudienceType::Member)->pluck('audience_id')->unique()->values()->all();

        foreach ($this->audiences->people()->find($refs) as $ref => $user) {
            $labels['member|'.$ref] = $this->audiences->name($user);
        }

        foreach ($this->audiences->roles() as $id => $name) {
            $labels['role|'.$id] = $name;
        }

        foreach ($this->audiences->groups() as $id => $name) {
            $labels['group|'.$id] = $name;
        }

        return $labels;
    }

    protected function find(Request $request): NotificationRoute
    {
        $id = (string) $request->route('route');

        abort_unless(RoutingLock::plausibleKey($id), 404);

        return NotificationRoute::query()->forCurrentTeam()->whereKey($id)->firstOrFail();
    }

    /**
     * @param array<string, mixed>|null $before
     */
    protected function changed(string $action, NotificationRoute $route, Request $request, ?array $before): void
    {
        $after = $action === 'deleted' ? null : ['type' => $route->type, 'audience_type' => $route->audience_type->value, 'audience_id' => $route->audience_id, 'channels' => $route->channels];

        NotificationRouteChanged::dispatch($action, 'route', $route->team_id, $request->user()?->getAuthIdentifier(), $before === null ? null : ['type' => $route->type, ...$before], $after);
    }
}
