<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Closure;
use Happones\Kinetix\Data\SpotlightItemData;
use Happones\Kinetix\Query\KinetixQuery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;

/**
 * A searchable model source. Searches through laravel/scout when the model is
 * Searchable (and the driver allows), otherwise a capped LIKE query. Results are
 * authorization-aware: an optional source-level ability, plus per-record `view`
 * policy filtering when the model has a policy.
 *
 * **`query()` scopes both drivers.** It is the seam a multi-tenant host binds a
 * source to the active team with, so it cannot be honored on one branch and
 * dropped on the other — a model adopting `Searchable` for unrelated reasons
 * would silently make every tenant's records searchable by every other tenant.
 * Under Scout the engine only *proposes* candidates; they are then hydrated
 * through this query, so a row it excludes can never reach the palette.
 *
 * The engine still spends its buffer before that filter runs, so a Scout source
 * scoped this way should also declare `scoutWhere()` on an indexed attribute —
 * otherwise the candidates are drawn from every tenant and the reader sees
 * fewer results than the limit.
 */
class SpotlightResource implements HasSpotlightPriority, SpotlightSource
{
    /**
     * How many pages of candidates the per-record policy pass may walk before
     * giving up.
     *
     * Bounds the worst case (a source whose policy rejects almost everything)
     * without under-filling the common one: a fixed over-fetch has to guess a
     * rejection rate, and guessing low silently returns fewer results than the
     * limit while matching, visible records go unshown.
     */
    protected const MAX_AUTHORIZATION_PAGES = 5;

    protected string $titleAttribute = 'name';

    protected string|Closure|null $subtitleAttribute = null;

    /** @var array<int, string> */
    protected array $searchColumns = ['name'];

    protected ?Closure $urlResolver = null;

    protected ?Closure $queryResolver = null;

    /** @var array<string, mixed> */
    protected array $scoutFilters = [];

    protected ?string $icon = null;

    protected ?string $group = null;

    protected ?string $ability = null;

    protected bool $trustsQuery = false;

    protected int $priority = 0;

    protected ?int $limit = null;

    /**
     * @param class-string<Model> $model
     */
    final public function __construct(public string $model) {}

    /**
     * @param class-string<Model> $model
     */
    public static function make(string $model): static
    {
        return new static($model);
    }

    public function titleAttribute(string $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    public function subtitle(string|Closure|null $attribute): static
    {
        $this->subtitleAttribute = $attribute;

        return $this;
    }

    /**
     * @param array<int, string> $columns
     */
    public function searchColumns(array $columns): static
    {
        $this->searchColumns = $columns;

        return $this;
    }

    public function url(Closure $resolver): static
    {
        $this->urlResolver = $resolver;

        return $this;
    }

    /**
     * The base query every result is drawn from — the tenancy seam. Honored by
     * both drivers (see the class docblock).
     */
    public function query(Closure $resolver): static
    {
        $this->queryResolver = $resolver;

        return $this;
    }

    /**
     * Engine-side filters for the Scout driver (`Scout\Builder::where()`), so
     * the engine's buffer is spent on rows this source can actually return.
     * A no-op on the database driver, where `query()` already filters in SQL.
     *
     * @param array<string, mixed> $filters
     */
    public function scoutWhere(array $filters): static
    {
        $this->scoutFilters = [...$this->scoutFilters, ...$filters];

        return $this;
    }

    /**
     * Declare `query()` authorization-complete and skip the per-record `view`
     * policy pass. A source scoped by a Resource's own query has usually
     * already answered the question, and the pass costs a policy call — often
     * a query of its own — per candidate row.
     */
    public function trustQuery(bool $trust = true): static
    {
        $this->trustsQuery = $trust;

        return $this;
    }

    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function authorize(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /** Higher sorts this source's group first. */
    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function authorizedFor(?Authenticatable $user): bool
    {
        return $this->ability === null || Gate::allows($this->ability);
    }

    /**
     * @return array<int, SpotlightItemData>
     */
    public function search(string $query): array
    {
        // Covers the empty query too: `minChars()` is never below 1. A single
        // character is the most expensive query this source can run — `%a%`
        // scans and hydrates almost the whole table — and it is the first
        // thing every user types.
        if (mb_strlen($query) < SpotlightController::minChars()) {
            return [];
        }

        $limit = $this->resolveLimit();

        if ($this->skipsRecordAuthorization()) {
            // The query IS the authorization: one page, exactly the limit.
            return $this->rank(
                $this->fetch($query, $limit, 1)->map(fn (Model $r): SpotlightItemData => $this->toItem($r))->all(),
                $query,
            );
        }

        $items = [];

        for ($page = 1; $page <= self::MAX_AUTHORIZATION_PAGES; $page++) {
            $candidates = $this->fetch($query, $limit, $page);

            foreach ($candidates as $record) {
                if (! Gate::allows('view', $record)) {
                    continue; // per-record authorization
                }

                $items[] = $this->toItem($record);

                if (count($items) >= $limit) {
                    return $this->rank($items, $query);
                }
            }

            if ($candidates->count() < $limit) {
                break; // the result set is exhausted, not the page budget
            }
        }

        return $this->rank($items, $query);
    }

    /**
     * A page of candidate records from whichever driver is active.
     *
     * @return Collection<int, Model>
     */
    protected function fetch(string $query, int $perPage, int $page): Collection
    {
        if ($this->usesScout()) {
            return $this->fetchViaScout($query, $perPage, $page);
        }

        $base = $this->queryResolver !== null ? ($this->queryResolver)() : $this->model::query();

        return KinetixQuery::search($base, $query, $this->searchColumns)
            // Paging without an order is not paging: rows repeat and rows go
            // missing between pages. The key is the only column every model is
            // guaranteed to have.
            ->orderBy((new $this->model)->getQualifiedKeyName())
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * Scout proposes candidates; `query()` decides which of them may be seen.
     *
     * @return Collection<int, Model>
     */
    protected function fetchViaScout(string $query, int $perPage, int $page): Collection
    {
        /** @var Builder $scout */
        $scout = $this->model::search($query);

        foreach ($this->scoutFilters as $field => $value) {
            $scout->where($field, $value);
        }

        $scout->take($perPage * $page);
        $offset = ($page - 1) * $perPage;

        if ($this->queryResolver === null) {
            return $scout->get()->slice($offset)->values();
        }

        $keys = $scout->keys()->slice($offset)->values();

        if ($keys->isEmpty()) {
            return new Collection;
        }

        $found = ($this->queryResolver)()
            ->whereKey($keys->all())
            ->get()
            ->keyBy(fn (Model $record): string => (string) $record->getKey());

        // Re-impose the engine's order: `whereKey` returns rows in whatever
        // order the database feels like, discarding the relevance ranking that
        // is the whole reason to run through Scout.
        return $keys
            ->map(fn (mixed $key): ?Model => $found->get((string) $key))
            ->filter()
            ->values();
    }

    /**
     * Float prefix matches above infix ones.
     *
     * Only for the database driver — the LIKE query produces no score at all,
     * so an exact match on a code would otherwise sort below a partial match
     * on a description. Scout results arrive ranked by the engine and are left
     * exactly as they came.
     *
     * @param  array<int, SpotlightItemData> $items
     * @return array<int, SpotlightItemData>
     */
    protected function rank(array $items, string $query): array
    {
        if ($this->usesScout()) {
            return $items;
        }

        $needle = Str::lower($query);

        // usort is stable as of PHP 8.0, so equal ranks keep their query order.
        usort(
            $items,
            fn (SpotlightItemData $a, SpotlightItemData $b): int => $this->prefixRank($b, $needle) <=> $this->prefixRank($a, $needle),
        );

        return $items;
    }

    protected function prefixRank(SpotlightItemData $item, string $needle): int
    {
        return str_starts_with(Str::lower($item->title), $needle) ? 1 : 0;
    }

    protected function toItem(Model $record): SpotlightItemData
    {
        return new SpotlightItemData(
            type: 'resource',
            group: $this->group ?? (string) str(class_basename($this->model))->plural()->headline(),
            title: (string) ($record->getAttribute($this->titleAttribute) ?? $record->getKey()),
            subtitle: $this->resolveSubtitle($record),
            url: $this->urlResolver !== null ? ($this->urlResolver)($record) : null,
            event: null,
            icon: $this->icon,
            id: $record->getKey(),
        );
    }

    protected function skipsRecordAuthorization(): bool
    {
        return $this->trustsQuery || Gate::getPolicyFor($this->model) === null;
    }

    protected function resolveLimit(): int
    {
        return $this->limit ?? max(1, (int) config('kinetix.spotlight.limit', 5));
    }

    protected function usesScout(): bool
    {
        $driver = (string) config('kinetix.spotlight.driver', 'auto');

        if ($driver === 'database') {
            return false;
        }

        if (! trait_exists(Searchable::class)) {
            return false;
        }

        return in_array(Searchable::class, class_uses_recursive($this->model), true);
    }

    protected function resolveSubtitle(Model $record): ?string
    {
        if ($this->subtitleAttribute === null) {
            return null;
        }

        if ($this->subtitleAttribute instanceof Closure) {
            $value = ($this->subtitleAttribute)($record);

            return $value === null ? null : (string) $value;
        }

        $value = $record->getAttribute($this->subtitleAttribute);

        return $value === null ? null : (string) $value;
    }
}
