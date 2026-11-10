<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Permissions\AssignableRoles;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * The people, roles and groups the routing screens offer for the current
 * team, looked up once per request.
 *
 * @internal
 */
class RoutingAudiences
{
    public function __construct(protected AudienceResolver $resolver) {}

    /**
     * The team whose people the screens list.
     */
    public function team(): int|string|null
    {
        return config('kinetix.teams', false) ? KinetixTeams::currentTeamKey() : null;
    }

    public function people(): RoutingPeople
    {
        return RoutingPeople::for($this->team());
    }

    /**
     * The people to pick from, first by name: all of a team's members, or one
     * page of a search when there are no teams.
     *
     * @return Collection<int, Model>
     */
    public function members(?string $search = null): Collection
    {
        return $this->people()->search($search, (string) config('kinetix.notification_routing.name_attribute', 'name'));
    }

    /**
     * The member a ref names, or null when they are not on the team.
     */
    public function member(string $ref): ?Model
    {
        return $this->people()->find([$ref])[$ref] ?? null;
    }

    public function name(Model $user): string
    {
        $name = $user->getAttribute((string) config('kinetix.notification_routing.name_attribute', 'name'));

        return is_string($name) && $name !== '' ? $name : (string) $user->getKey();
    }

    /**
     * The roles the team can see (its own and the global ones), keyed by id.
     * Empty without spatie/laravel-permission.
     *
     * @return Collection<string, string>
     */
    public function roles(): Collection
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return new Collection;
        }

        return AssignableRoles::query($this->team())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Model $role): array => [(string) $role->getKey() => (string) $role->getAttribute('name')]);
    }

    /**
     * The team's groups' names, keyed by id.
     *
     * @return Collection<string, string>
     */
    public function groups(): Collection
    {
        return NotificationGroup::query()->forCurrentTeam()->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(static fn (NotificationGroup $group): array => [(string) $group->getKey() => $group->name]);
    }
}
