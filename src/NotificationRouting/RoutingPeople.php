<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Generator;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\Memo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The people a team's routing can reach, and the one identity they go by: a
 * member ref, `{morphClass}|{key}`, so a Client #5 is never a User #5.
 *
 * Two ways to know them:
 *
 *  - a CLOSED list: the team model's members relation, or the host's
 *    `notification_routing.members` callable, in an app with teams on or
 *    whenever the host gave a callable. Everything is intersected with it. With
 *    teams on and no way to resolve the team's members it is EMPTY: routing
 *    never falls back to every user.
 *  - an OPEN set: no teams and no callable, so the user model is the audience.
 *    Nobody is capped to a window: named members, role holders and group
 *    members are loaded by key, and the `eligible` audience is read in ordered
 *    chunks.
 *
 * @internal
 */
class RoutingPeople
{
    /** How many users the open set's chunked read and the audience search take at once. */
    public const CHUNK = 200;

    /** How many members the audience picker returns at once. */
    public const PAGE = 200;

    protected function __construct(protected int|string|null $teamKey) {}

    public static function for(int|string|null $teamKey): self
    {
        return new self($teamKey);
    }

    /**
     * Whether people are known as a closed list (teams on, or a host callable).
     */
    public function closed(): bool
    {
        return (bool) config('kinetix.teams', false) || $this->callable() !== null;
    }

    /**
     * The member ref of a person.
     */
    public static function ref(Model $user): string
    {
        return NotificationPreferenceManager::ownerKey($user);
    }

    /**
     * The model type and key a member ref names, or null when it is no ref.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $ref): ?array
    {
        $at = strpos($ref, '|');

        if ($at === false || $at === 0 || $at === strlen($ref) - 1) {
            return null;
        }

        return [substr($ref, 0, $at), substr($ref, $at + 1)];
    }

    /**
     * The closed list, by member ref. Empty for an open set.
     *
     * @return array<string, Model>
     */
    public function members(): array
    {
        if (! $this->closed()) {
            return [];
        }

        /** @var array<string, Model> */
        return Memo::remember(
            AudienceResolver::MEMO,
            request(),
            'members:'.($this->teamKey ?? '-'),
            fn (): array => KinetixTeams::members(
                $this->teamKey,
                config('kinetix.notification_routing.members'),
                (string) config('kinetix.notification_routing.members_relation', 'members'),
                failClosed: (bool) config('kinetix.teams', false),
            )->keyBy(static fn (Model $user): string => self::ref($user))->all(),
        );
    }

    /**
     * The people the refs name that are in the team (or, in an open set,
     * exist), by ref. A ref nobody holds is left out.
     *
     * @param  list<string>         $refs
     * @return array<string, Model>
     */
    public function find(array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        if ($this->closed()) {
            return array_intersect_key($this->members(), array_flip($refs));
        }

        $class = $this->userClass();

        if ($class === null) {
            return [];
        }

        $morph = (new $class)->getMorphClass();
        $keys  = [];

        foreach ($refs as $ref) {
            $parsed = self::parse($ref);

            if ($parsed !== null && ($parsed[0] === $morph || $parsed[0] === $class) && RoutingLock::plausibleKey($parsed[1], (new $class)->getKeyType())) {
                $keys[] = $parsed[1];
            }
        }

        if ($keys === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            foreach ($class::query()->whereIn((new $class)->getQualifiedKeyName(), $chunk)->get() as $user) {
                $found[self::ref($user)] = $user;
            }
        }

        return $found;
    }

    /**
     * Everyone, in chunks, in key order: the whole closed list at once, or the
     * open set read lazily.
     *
     * @return Generator<int, Collection<int, Model>>
     */
    public function chunks(): Generator
    {
        if ($this->closed()) {
            $members = $this->members();
            ksort($members);

            yield collect(array_values($members));

            return;
        }

        $class = $this->userClass();

        if ($class === null) {
            return;
        }

        $buffer = [];

        foreach ($class::query()->lazyById(self::CHUNK) as $user) {
            $buffer[] = $user;

            if (count($buffer) === self::CHUNK) {
                yield collect($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield collect($buffer);
        }
    }

    /**
     * The people to pick from, first by name, filtered by a search: at most
     * one page and one more (so a caller can tell the page is complete).
     *
     * @return Collection<int, Model>
     */
    public function search(?string $term, string $nameAttribute): Collection
    {
        if ($this->closed()) {
            $needle = $term === null ? '' : mb_strtolower($term);

            return collect($this->members())
                ->filter(static fn (Model $user): bool => $needle === '' || str_contains(mb_strtolower((string) $user->getAttribute($nameAttribute)), $needle))
                ->sortBy(static fn (Model $user): string => mb_strtolower((string) $user->getAttribute($nameAttribute)))
                ->take(self::PAGE + 1)
                ->values();
        }

        $class = $this->userClass();

        if ($class === null) {
            return new Collection;
        }

        $model  = new $class;
        $query  = $class::query();
        $sorted = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), $nameAttribute);

        if ($sorted && $term !== null && $term !== '') {
            $wrapped = $query->getQuery()->getGrammar()->wrap($model->qualifyColumn($nameAttribute));

            // `!` escapes, so the wildcard characters of the term mean themselves on every database.
            $query->whereRaw("{$wrapped} like ? escape '!'", ['%'.preg_replace('/([!%_])/', '!$1', $term).'%']);
        }

        return $query
            ->orderBy($sorted ? $nameAttribute : $model->getKeyName())
            ->limit(self::PAGE + 1)
            ->get()
            ->values();
    }

    /**
     * The user model of an open set.
     *
     * @return class-string<Model>|null
     */
    protected function userClass(): ?string
    {
        $class = config('auth.providers.users.model');

        return is_string($class) && class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    protected function callable(): ?callable
    {
        return ConfigCallback::resolve(config('kinetix.notification_routing.members'));
    }
}
