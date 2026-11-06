<?php

declare(strict_types=1);

namespace Happones\Kinetix\Teams;

use BackedEnum;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\KinetixTrans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UnitEnum;

/**
 * Resolves the active user's teams (and a ready-made switch URL for each) for
 * the <KinetixTeamSwitcher>. Kinetix does not own the Team model: it reads the
 * teams by the convention configured in `kinetix.team_switcher` and delegates
 * the actual switch to the host's route, so it stays model-agnostic.
 */
class TeamSwitcherManager
{
    /** How many teams a search returns with no `max_teams`. */
    private const DEFAULT_SEARCH_LIMIT = 20;

    /**
     * The shared-prop payload: the user's teams (each with a switch URL), the
     * current team, and an optional "create team" URL.
     *
     * Each team carries what tells it apart at a glance besides its name,
     * when `kinetix.team_switcher.logo` / `.indicator` turn them on: its mark
     * (a logo, else the default logo — an image or an icon — else its
     * initials) and an indicator in its type's color (a dot, or an icon).
     *
     * @return array{
     *     enabled: bool,
     *     teams: array<int, array{id: int|string, key: mixed, name: string, url: string|null, current: bool, initials: string, logo: string|null, logoIcon: string|null, accent: string|null, icon: string|null}>,
     *     current: array{id: int|string, name: string, initials: string, logo: string|null, logoIcon: string|null, accent: string|null, icon: string|null}|null,
     *     createUrl: string|null,
     *     switchMethod: string,
     *     labels: array{switch: string, select: string, create: string},
     *     total?: int,
     *     truncated?: bool,
     *     searchUrl?: string|null
     * }
     *
     * The list is capped at `team_switcher.max_teams` (the current team is
     * always in it): `truncated` says more exist, `total` how many, and
     * `searchUrl` where to search for the rest.
     */
    public function payload(): array
    {
        $user = auth()->user();

        if (! config('kinetix.team_switcher.enabled', false) || ! $user instanceof Model) {
            return ['enabled' => false, 'teams' => [], 'current' => null, 'createUrl' => null, 'switchMethod' => 'get', 'labels' => $this->labels()];
        }

        $current    = $this->currentTeam($user);
        $currentKey = $current?->getKey();

        [$listed, $truncated, $total] = $this->listedTeams($user);

        // The current team beyond the cut is still listed (once).
        if ($truncated && $current instanceof Model && ! $listed->contains(static fn (Model $team): bool => $team->getKey() === $currentKey)) {
            $listed->push($current);
        }

        $teams = $listed
            ->map(fn (Model $team): array => $this->option($team, $team->getKey() === $currentKey))
            ->values()
            ->all();

        return [
            'enabled' => true,
            'teams'   => $teams,
            'current' => $current instanceof Model
                ? ['id' => $current->getKey(), 'name' => $this->name($current), ...$this->identity($current)]
                : null,
            'createUrl' => $this->createUrl(),
            // How to visit a switch URL: the starter-kit's switch route is a
            // POST, and a GET to it is a 405.
            'switchMethod' => $this->switchMethod(),
            'labels'       => $this->labels(),
            'total'        => $total,
            'truncated'    => $truncated,
            'searchUrl'    => $truncated && Route::has('kinetix.team-switcher.search') ? route('kinetix.team-switcher.search') : null,
        ];
    }

    /**
     * The user's own teams whose search column contains `$query`
     * (case-insensitive; `%`, `_` and `\` match literally), capped like the
     * shared list. Never a team the user isn't a member of: the search runs
     * on the user's teams relation only. `current` is always false.
     *
     * @return array{teams: list<array<string, mixed>>, truncated: bool}
     */
    public function search(Model $user, string $query): array
    {
        $relation = $this->teamsRelation($user);

        if ($relation === null) {
            return ['teams' => [], 'truncated' => false];
        }

        $related = $relation->getRelated();
        $base    = $relation->getQuery()->getQuery();
        $column  = $base->getGrammar()->wrap($related->qualifyColumn($this->searchColumn()));
        $like    = $related->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $limit   = $this->maxTeams() ?? self::DEFAULT_SEARCH_LIMIT;

        $relation->whereRaw("{$column} {$like} ? escape ?", ['%'.$escaped.'%', '\\']);

        if ($base->orders === null || $base->orders === []) {
            $relation->orderBy($related->getQualifiedKeyName());
        }

        $found     = $relation->limit($limit + 1)->get();
        $truncated = $found->count() > $limit;

        return [
            'teams' => $found->take($limit)
                ->map(fn (Model $team): array => $this->option($team, false))
                ->values()
                ->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * One team as the switcher lists it.
     *
     * @return array<string, mixed>
     */
    protected function option(Model $team, bool $current): array
    {
        return [
            'id' => $team->getKey(),
            // The team in a URL (`/{key}/...`): what a switch that keeps
            // the page swaps in.
            'key'     => $team->getRouteKey(),
            'name'    => $this->name($team),
            'url'     => $this->switchUrl($team),
            'current' => $current,
            ...$this->identity($team),
        ];
    }

    /**
     * The teams the shared list holds, whether more exist, and how many
     * there are. Without a cap: all of them ({@see teamsFor()}). With one: an
     * already-loaded relation is sliced (no query); otherwise one query asks
     * for one more than the cap (stable order: the relation's own, else the
     * key), and only a user beyond the cap costs a count query.
     *
     * @return array{0: Collection<int, Model>, 1: bool, 2: int}
     */
    protected function listedTeams(Model $user): array
    {
        $max = $this->maxTeams();

        if ($max === null) {
            $teams = $this->teamsFor($user);

            return [$teams, false, $teams->count()];
        }

        $name     = (string) config('kinetix.team_switcher.teams_relation', 'teams');
        $relation = $user->relationLoaded($name) ? null : $this->teamsRelation($user);

        if ($relation === null) {
            $teams = $this->teamsFor($user);

            return [$teams->take($max)->values(), $teams->count() > $max, $teams->count()];
        }

        $counter = clone $relation;
        $base    = $relation->getQuery()->getQuery();

        if ($base->orders === null || $base->orders === []) {
            $relation->orderBy($relation->getRelated()->getQualifiedKeyName());
        }

        $teams     = collect($relation->limit($max + 1)->get())->values();
        $truncated = $teams->count() > $max;

        if (! $truncated) {
            return [$teams, false, $teams->count()];
        }

        return [$teams->take($max)->values(), true, $counter->count()];
    }

    /** A fresh query on the user's teams relation; null when it isn't one. */
    protected function teamsRelation(Model $user): ?Relation
    {
        $name = (string) config('kinetix.team_switcher.teams_relation', 'teams');

        if (! method_exists($user, $name)) {
            return null;
        }

        $relation = $user->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }

    /** `team_switcher.max_teams`: a positive cap, or null for none. */
    protected function maxTeams(): ?int
    {
        $max = config('kinetix.team_switcher.max_teams', 20);

        return is_numeric($max) && (int) $max > 0 ? (int) $max : null;
    }

    /** The column the search matches: `search_column`, else the name attribute. */
    protected function searchColumn(): string
    {
        $column = config('kinetix.team_switcher.search_column');

        return is_string($column) && $column !== '' ? $column : $this->nameAttribute();
    }

    /**
     * Where to send someone who just created a team, per
     * `kinetix.team_switcher.redirect_after_create`: a route name (given the
     * team's route key), a callable array receiving the team and returning a
     * URL, or null for the app's own default.
     */
    public function redirectAfterCreate(Model $team, string $default): string
    {
        $setting  = config('kinetix.team_switcher.redirect_after_create');
        $callback = ConfigCallback::resolve($setting);

        if ($callback !== null) {
            $url = $callback($team);

            return is_string($url) && $url !== '' ? $url : $default;
        }

        if (is_string($setting) && Route::has($setting)) {
            return route($setting, $team->getRouteKey());
        }

        return $default;
    }

    /**
     * What tells a team apart besides its name: its mark on the left (`logo`
     * an image URL, or `logoIcon` the default logo's icon, with `initials`
     * last) and its indicator on the right (`accent` its color; `icon` the
     * icon in place of the dot).
     *
     * @return array{initials: string, logo: string|null, logoIcon: string|null, accent: string|null, icon: string|null}
     */
    protected function identity(Model $team): array
    {
        [$logo, $logoIcon] = config('kinetix.team_switcher.logo.enabled', false) ? $this->logo($team) : [null, null];
        $indicator         = (bool) config('kinetix.team_switcher.indicator.enabled', false);

        return [
            'initials' => $this->initials($this->name($team)),
            'logo'     => $logo,
            'logoIcon' => $logoIcon,
            'accent'   => $indicator ? $this->accent($team) : null,
            'icon'     => $indicator ? $this->indicatorIcon($team) : null,
        ];
    }

    protected function name(Model $team): string
    {
        return (string) $team->getAttribute($this->nameAttribute());
    }

    /** "Acme Coffee Co" → "AC": the mark when there's no logo at all. */
    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = array_map(static fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $first)) ?: '?';
    }

    /**
     * The team's logo as [image URL, icon]: a resolver's answer, else the
     * attribute; a team without one gets `logo.default` — an image (a URL, a
     * root path, or a path with an image extension on the disk) or a Lucide
     * icon name. A default icon comes along with a logo too: it stands in
     * when the image fails to load.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function logo(Model $team): array
    {
        $value   = $this->callback('logo.resolver', $team) ?? $team->getAttribute((string) config('kinetix.team_switcher.logo.attribute', 'logo'));
        $default = $this->defaultLogo();
        $icon    = $default === null || $this->isImage($default) ? null : $default;

        if (is_string($value) && $value !== '') {
            return [$this->imageUrl($value), $icon];
        }

        return [$default === null || $icon !== null ? null : $this->imageUrl($default), $icon];
    }

    protected function defaultLogo(): ?string
    {
        $default = config('kinetix.team_switcher.logo.default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    protected function isImage(string $value): bool
    {
        return preg_match('#^(https?://|/|data:)#', $value)              === 1
            || preg_match('/\.(png|jpe?g|gif|svg|webp|avif)$/i', $value) === 1;
    }

    /** A full URL or root path as it is; a path stored on `logo.disk` (or the default disk) through its URL. */
    protected function imageUrl(string $value): string
    {
        if (preg_match('#^(https?://|/|data:)#', $value) === 1) {
            return $value;
        }

        $disk = config('kinetix.team_switcher.logo.disk');

        return is_string($disk) && $disk !== '' ? Storage::disk($disk)->url($value) : Storage::url($value);
    }

    /**
     * The indicator's CSS color: a resolver's answer, else the attribute — an
     * enum's getColor(), a value the `indicator.colors` map names, or a color
     * written out (#hex, rgb(), oklch(), var()). Kinetix's status names
     * (primary, success, warning, danger, info, gray) become their theme
     * tokens, so they follow light and dark mode.
     */
    protected function accent(Model $team): ?string
    {
        $color = $this->callback('indicator.resolver', $team);

        if ($color === null) {
            $value = $this->indicatorValue($team);
            $color = $this->fromEnum($value, 'getColor') ?? $this->mapped('colors', $value) ?? $this->key($value);
        }

        if (! is_string($color) || $color === '') {
            return null;
        }

        return match ($color) {
            'primary' => 'var(--color-primary)',
            'success' => 'var(--color-success)',
            'warning' => 'var(--color-warning)',
            'danger'  => 'var(--color-destructive)',
            'info'    => 'var(--color-info)',
            'gray'    => 'var(--color-muted-foreground)',
            default   => preg_match('/^(#|rgb|hsl|oklch|oklab|lab|lch|color\(|var\()/i', $color) === 1 ? $color : null,
        };
    }

    /**
     * The icon in place of the indicator's dot (a Lucide name), or null for
     * the dot: `icon_resolver`'s answer, else the type's — an enum's
     * getIcon() when `enum_icons` is on, the `icons` map's — else `icon`.
     */
    protected function indicatorIcon(Model $team): ?string
    {
        $icon = $this->callback('indicator.icon_resolver', $team);

        if ($icon === null) {
            $value = $this->indicatorValue($team);
            $icon  = (config('kinetix.team_switcher.indicator.enum_icons', false) ? $this->fromEnum($value, 'getIcon') : null)
                ?? $this->mapped('icons', $value)
                ?? config('kinetix.team_switcher.indicator.icon');
        }

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    /** A `team_switcher.{key}` callable array's answer for the team. */
    protected function callback(string $key, Model $team): mixed
    {
        $callback = ConfigCallback::resolve(config("kinetix.team_switcher.{$key}"));

        return $callback !== null ? $callback($team) : null;
    }

    /** The team's type: `indicator.attribute` read off the team. */
    protected function indicatorValue(Model $team): mixed
    {
        $attribute = config('kinetix.team_switcher.indicator.attribute');

        return is_string($attribute) && $attribute !== '' ? $team->getAttribute($attribute) : null;
    }

    /** An enum type's own answer (getColor/getIcon). */
    protected function fromEnum(mixed $value, string $method): ?string
    {
        if (! $value instanceof UnitEnum || ! method_exists($value, $method)) {
            return null;
        }

        $answer = $value->{$method}();

        return is_string($answer) && $answer !== '' ? $answer : null;
    }

    /** What the `indicator.{map}` map says for the type. */
    protected function mapped(string $map, mixed $value): ?string
    {
        $key    = $this->key($value);
        $mapped = $key !== null ? config("kinetix.team_switcher.indicator.{$map}.{$key}") : null;

        return is_string($mapped) && $mapped !== '' ? $mapped : null;
    }

    /** The type as a map key: an enum's value (or name), a scalar as text. */
    protected function key(mixed $value): ?string
    {
        $key = match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum   => $value->name,
            is_scalar($value)            => (string) $value,
            default                      => null,
        };

        return $key === '' ? null : $key;
    }

    /**
     * The switcher's words, in the app's own terms ("New account" where a
     * team is an account): translation keys or plain strings.
     *
     * @return array{switch: string, select: string, create: string}
     */
    protected function labels(): array
    {
        $labels = (array) config('kinetix.team_switcher.labels', []);
        $say    = static fn (mixed $value, string $default): string => KinetixTrans::line(is_string($value) && $value !== '' ? $value : $default);

        return [
            'switch' => $say($labels['switch'] ?? null, 'kinetix.teams_switch'),
            'select' => $say($labels['select'] ?? null, 'kinetix.teams_select'),
            'create' => $say($labels['create'] ?? null, 'kinetix.teams_new'),
        ];
    }

    /**
     * The collection of teams the user belongs to.
     *
     * @return Collection<int, Model>
     */
    protected function teamsFor(Model $user): Collection
    {
        $relation = config('kinetix.team_switcher.teams_relation', 'teams');
        $teams    = $user->getAttribute($relation);

        return collect($teams)->filter(fn ($team): bool => $team instanceof Model)->values();
    }

    /**
     * The team to mark as current: with teams on and a team in the URL, that
     * team — through KinetixTeams' memoized resolution and membership check —
     * else (no team in the URL, or the legacy mode without a teams relation)
     * the user's stored current team (`team_switcher.current_relation`).
     * A URL team the user doesn't belong to marks none: the switcher never
     * 404s and never points at a team the URL doesn't name.
     */
    protected function currentTeam(Model $user): ?Model
    {
        $request = request();

        if (config('kinetix.teams', false) && ($request->route('current_team') ?? $request->route('team')) !== null) {
            try {
                $team = KinetixTeams::currentTeam($request);
            } catch (HttpExceptionInterface) {
                return null;
            }

            // Null in the legacy mode without a teams relation (only the raw
            // segment is known): the stored team, as before.
            if ($team !== null) {
                return $team;
            }
        }

        $relation = config('kinetix.team_switcher.current_relation', 'currentTeam');
        $team     = $user->getAttribute($relation);

        return $team instanceof Model ? $team : null;
    }

    protected function switchUrl(Model $team): ?string
    {
        $route = config('kinetix.team_switcher.switch_route', 'teams.switch');

        return is_string($route) && Route::has($route)
            ? route($route, $team->getRouteKey())
            : null;
    }

    /**
     * The switch route's HTTP verb, lower-case: `get` when it takes one,
     * else the first it declares (`post`, `put`, `patch`).
     */
    protected function switchMethod(): string
    {
        $route = config('kinetix.team_switcher.switch_route', 'teams.switch');
        $route = is_string($route) ? Route::getRoutes()->getByName($route) : null;

        if ($route === null || in_array('GET', $route->methods(), true)) {
            return 'get';
        }

        return strtolower($route->methods()[0]);
    }

    protected function createUrl(): ?string
    {
        $route = config('kinetix.team_switcher.create_route');

        return is_string($route) && Route::has($route) ? route($route) : null;
    }

    protected function nameAttribute(): string
    {
        return (string) config('kinetix.team_switcher.name_attribute', 'name');
    }
}
