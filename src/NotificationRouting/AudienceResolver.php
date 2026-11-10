<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Turns a team's rules for a type into the people a notification reaches and
 * the channels it goes out on.
 *
 * Every audience is bounded to the team's CURRENT members ({@see RoutingPeople}),
 * so a stale role assignment or group row for someone who left the team reaches
 * nobody, and a team whose members can't be resolved reaches nobody. The team is
 * always the caller's: the resolver never infers it, and pins it for the
 * ability checks. Queries stay constant for a whole audience (routes, members,
 * one role query, one group query, one preferences query, and two for the
 * required ability's roles and permissions per batch of 200 people); the
 * ability itself is judged per person, in memory.
 *
 * Per person, the channels are the union of their rules' channels, kept to the
 * ones the type is delivered on (and the notification can render), then
 * without the ones they opted out of, except the type's mandatory channels.
 */
class AudienceResolver
{
    /** The {@see Memo} store routes and members live in. */
    public const MEMO = 'kinetix.notification-routing';

    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected NotificationPreferenceManager $preferences,
    ) {}

    /** The ability the type in flight requires, and each person's verdict. */
    protected ?string $ability = null;

    /** @var array<string, bool> */
    protected array $verdicts = [];

    /** @var array<string, true> */
    protected array $prepared = [];

    /**
     * People the chunked read left out for lacking the ability, which are not
     * kept as models: a sample for the preview, and how many in all.
     *
     * @var list<SkippedRecipient>
     */
    protected array $scannedOut = [];

    protected int $scannedOutCount = 0;

    /**
     * @param Collection<int, Model>|null $fallback     people to use when the team has no rule for the type
     * @param object|null                 $notification the notification to send; null for a dry run that ignores what it can render
     */
    public function resolve(string $type, int|string|null $teamKey, ?object $notification = null, ?Collection $fallback = null): RecipientPlan
    {
        return PermissionTeam::pin($teamKey, fn (): RecipientPlan => $this->plan($type, $teamKey, $notification, $fallback ?? collect()));
    }

    /**
     * @param Collection<int, Model> $fallback
     */
    protected function plan(string $type, int|string|null $teamKey, ?object $notification, Collection $fallback): RecipientPlan
    {
        $this->ability         = $this->registry->requirementOf($type);
        $this->verdicts        = [];
        $this->prepared        = [];
        $this->scannedOut      = [];
        $this->scannedOutCount = 0;

        $people   = RoutingPeople::for($teamKey);
        $routes   = $this->routes($teamKey, $type);
        $base     = $this->preferences->channelsOf($type);
        $max      = (int) config('kinetix.notification_routing.max_recipients', 500);
        $users    = [];
        $channels = [];
        $fellBack = false;

        if ($routes->isEmpty()) {
            $fellBack = $fallback->isNotEmpty();

            foreach ($fallback as $user) {
                $users[RoutingPeople::ref($user)] = $user;
            }

            // The fallback is held to the team too.
            if ($people->closed()) {
                $users = array_intersect_key($users, $people->members());
            }

            foreach ($users as $ref => $user) {
                $channels[$ref] = $base;
            }

            if (! $fellBack) {
                Log::warning('Notification routing: the team has no route for the type and no fallback was given; nobody is notified.', ['type' => $type]);
            }
        } else {
            [$byRef, $eligible] = $this->expand($routes, $teamKey, $base);

            foreach ($people->find(array_keys($byRef)) as $ref => $user) {
                $users[$ref]    = $user;
                $channels[$ref] = $byRef[$ref];
            }

            if ($eligible !== null) {
                $this->prepare(collect(array_values($users)));

                foreach ($people->chunks() as $chunk) {
                    $this->prepare($chunk);

                    foreach ($chunk as $user) {
                        $ref = RoutingPeople::ref($user);

                        // Someone the ability rules out is counted, not kept.
                        if (! isset($users[$ref]) && ! $this->passes($user)) {
                            $this->scannedOutCount++;

                            if (count($this->scannedOut) < RecipientPlan::SKIPPED_SAMPLE) {
                                $this->scannedOut[] = new SkippedRecipient($user, SkipReason::Ineligible);
                            }

                            continue;
                        }

                        $users[$ref] ??= $user;
                        $channels[$ref] = array_values(array_unique([...($channels[$ref] ?? []), ...$eligible]));
                    }

                    // An open set is read only as far as the cap needs.
                    if ($max > 0 && ! $people->closed() && $this->passing($users) > $max) {
                        break;
                    }
                }
            }
        }

        return $this->judge($type, $users, $channels, $notification, $routes->isNotEmpty(), $fellBack);
    }

    /**
     * @param array<string, Model> $users
     */
    protected function passing(array $users): int
    {
        return count(array_filter($users, fn (Model $user): bool => $this->passes($user)));
    }

    /**
     * Whether the person holds the type's required ability, judged once.
     */
    protected function passes(Model $user): bool
    {
        if ($this->ability === null) {
            return true;
        }

        return $this->verdicts[RoutingPeople::ref($user)] ??= Gate::forUser($user)->allows($this->ability);
    }

    /**
     * Load what the ability check reads (spatie's roles and permissions) for a
     * batch in two queries, instead of two per person. The team is the one
     * the resolution is pinned to, and nothing loaded earlier survives.
     *
     * @param Collection<int, Model> $users
     */
    protected function prepare(Collection $users): void
    {
        if ($this->ability === null) {
            return;
        }

        $fresh = $users->reject(fn (Model $user): bool => isset($this->prepared[RoutingPeople::ref($user)]));

        foreach ($fresh->groupBy(static fn (Model $user): string => $user::class) as $group) {
            $first = $group->first();

            if ($first === null || ! method_exists($first, 'roles') || ! method_exists($first, 'permissions')) {
                continue;
            }

            foreach ($group as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $this->prepared[RoutingPeople::ref($user)] = true;
            }

            (new EloquentCollection($group->all()))->load(['roles', 'permissions']);
        }
    }

    /**
     * Eligibility, renderable channels, then preferences.
     *
     * @param array<string, Model>        $users
     * @param array<string, list<string>> $channels
     */
    protected function judge(string $type, array $users, array $channels, ?object $notification, bool $hasRules, bool $fellBack): RecipientPlan
    {
        ksort($users);

        $this->prepare(collect(array_values($users)));

        $skipped = [];
        $reached = [];

        foreach ($users as $key => $user) {
            if (! $this->passes($user)) {
                $skipped[] = new SkippedRecipient($user, SkipReason::Ineligible);

                continue;
            }

            $granted = $notification === null ? $channels[$key] : RenderableChannels::of($notification, $channels[$key]);

            if ($granted === []) {
                $skipped[] = new SkippedRecipient($user, SkipReason::NoChannels);

                continue;
            }

            $reached[$key] = $granted;
        }

        $delivered  = $this->preferences->deliverableMany(collect(array_intersect_key($users, $reached))->values(), $type, $reached);
        $recipients = [];

        foreach (array_keys($reached) as $key) {
            $kept = $delivered[$key] ?? [];

            if ($kept === []) {
                $skipped[] = new SkippedRecipient($users[$key], SkipReason::OptedOut);

                continue;
            }

            $recipients[] = new PlannedRecipient($users[$key], $kept);
        }

        $max       = (int) config('kinetix.notification_routing.max_recipients', 500);
        $truncated = $max > 0 && count($recipients) > $max;

        if ($truncated) {
            Log::warning('Notification routing: more recipients than max_recipients; the rest are not notified.', ['type' => $type, 'recipients' => count($recipients), 'max' => $max]);
            $recipients = array_slice($recipients, 0, $max);
        }

        $skippedCount = count($skipped) + $this->scannedOutCount;
        $sample       = array_slice([...$skipped, ...$this->scannedOut], 0, RecipientPlan::SKIPPED_SAMPLE);

        return new RecipientPlan($recipients, $sample, $skippedCount, $hasRules, $fellBack, $truncated);
    }

    /**
     * The channels each person is granted by the rules, keyed by member ref,
     * and the channels of the `eligible` audience (null without one). A
     * person a rule reaches without a usable channel is kept with an empty
     * list, so a preview can say why they get nothing.
     *
     * @param  Collection<int, NotificationRoute>                          $routes
     * @param  list<string>                                                $base
     * @return array{0: array<string, list<string>>, 1: list<string>|null}
     */
    protected function expand(Collection $routes, int|string|null $teamKey, array $base): array
    {
        $roles    = $this->roleHolders($routes, $teamKey);
        $groups   = $this->groupHolders($routes, $teamKey);
        $granted  = [];
        $eligible = null;

        foreach ($routes as $route) {
            $usable = array_values(array_intersect($base, (array) $route->channels));

            $targets = match ($route->audience_type) {
                AudienceType::Member   => [$route->audience_id],
                AudienceType::Eligible => [],
                AudienceType::Role     => $roles[$route->audience_id]  ?? [],
                AudienceType::Group    => $groups[$route->audience_id] ?? [],
            };

            if ($route->audience_type === AudienceType::Eligible) {
                $eligible = array_values(array_unique([...($eligible ?? []), ...$usable]));
            }

            foreach ($targets as $ref) {
                $granted[$ref] = array_values(array_unique([...($granted[$ref] ?? []), ...$usable]));
            }
        }

        return [$granted, $eligible];
    }

    /**
     * Who holds each role the rules name, in one query on the team's
     * assignments (plus the global ones).
     *
     * @param  Collection<int, NotificationRoute> $routes
     * @return array<string, list<string>>
     */
    protected function roleHolders(Collection $routes, int|string|null $teamKey): array
    {
        $ids = $routes->where('audience_type', AudienceType::Role)->pluck('audience_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $query = DB::table((string) config('permission.table_names.model_has_roles', 'model_has_roles'))->whereIn('role_id', $ids);

        if (PermissionTeam::active()) {
            $column = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');

            $query->where(static function ($scope) use ($column, $teamKey): void {
                $scope->whereNull($column);

                if ($teamKey !== null) {
                    $scope->orWhere($column, $teamKey);
                }
            });
        }

        $holders = [];

        foreach ($query->get(['role_id', 'model_type', 'model_id']) as $row) {
            $holders[(string) $row->role_id][] = $row->model_type.'|'.$row->model_id;
        }

        return $holders;
    }

    /**
     * The members of each group the rules name, in one query, only for the
     * groups that belong to the team.
     *
     * @param  Collection<int, NotificationRoute> $routes
     * @return array<string, list<string>>
     */
    protected function groupHolders(Collection $routes, int|string|null $teamKey): array
    {
        $ids = $routes->where('audience_type', AudienceType::Group)->pluck('audience_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = NotificationGroupMember::query()
            ->whereIn('group_id', NotificationGroup::query()->where('team_id', $this->scopeKey($teamKey))->whereIn('id', $ids)->select('id'))
            ->get(['group_id', 'user_type', 'user_id']);

        $holders = [];

        foreach ($rows as $row) {
            $holders[(string) $row->group_id][] = $row->user_type.'|'.$row->user_id;
        }

        return $holders;
    }

    /**
     * The team's rules for a type, in one query per request.
     *
     * @return Collection<int, NotificationRoute>
     */
    protected function routes(int|string|null $teamKey, string $type): Collection
    {
        if (! config('kinetix.notification_routing.enabled', false)) {
            return new Collection;
        }

        $scope = $this->scopeKey($teamKey);

        /** @var Collection<int, NotificationRoute> */
        return Memo::remember(
            self::MEMO,
            request(),
            'routes:'.($scope ?? '-').':'.$type,
            static fn (): Collection => NotificationRoute::query()->where('team_id', $scope)->where('type', $type)->get(),
        );
    }

    /**
     * The `team_id` rules and groups are stored under: the team while the
     * module is team-scoped, null otherwise.
     */
    public function scopeKey(int|string|null $teamKey): int|string|null
    {
        return KinetixTeams::enabledFor('notification_routing') ? $teamKey : null;
    }
}
