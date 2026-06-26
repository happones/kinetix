<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Closure;
use Happones\Kinetix\Data\SpotlightItemData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Scout\Searchable;

/**
 * A searchable model source. Searches through laravel/scout when the model is
 * Searchable (and the driver allows), otherwise a capped LIKE query. Results are
 * authorization-aware: an optional source-level ability, plus per-record `view`
 * policy filtering when the model has a policy.
 */
class SpotlightResource implements SpotlightSource
{
    protected string $titleAttribute = 'name';

    protected string|Closure|null $subtitleAttribute = null;

    /** @var array<int, string> */
    protected array $searchColumns = ['name'];

    protected ?Closure $urlResolver = null;

    protected ?Closure $queryResolver = null;

    protected ?string $icon = null;

    protected ?string $group = null;

    protected ?string $ability = null;

    protected int $limit = 5;

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

    public function query(Closure $resolver): static
    {
        $this->queryResolver = $resolver;

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
        if ($query === '') {
            return [];
        }

        $hasPolicy = Gate::getPolicyFor($this->model) !== null;
        $items     = [];

        foreach ($this->fetch($query) as $record) {
            if ($hasPolicy && ! Gate::allows('view', $record)) {
                continue; // per-record authorization
            }

            $items[] = new SpotlightItemData(
                type: 'resource',
                group: $this->group ?? (string) str(class_basename($this->model))->plural()->headline(),
                title: (string) ($record->getAttribute($this->titleAttribute) ?? $record->getKey()),
                subtitle: $this->resolveSubtitle($record),
                url: $this->urlResolver !== null ? ($this->urlResolver)($record) : null,
                event: null,
                icon: $this->icon,
                id: $record->getKey(),
            );

            if (count($items) >= $this->limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return iterable<int, Model>
     */
    protected function fetch(string $query): iterable
    {
        $buffer = $this->limit * 3;

        if ($this->usesScout()) {
            return $this->model::search($query)->take($buffer)->get();
        }

        $base = $this->queryResolver !== null ? ($this->queryResolver)() : $this->model::query();

        return $base->where(function ($builder) use ($query): void {
            foreach ($this->searchColumns as $column) {
                $builder->orWhere($column, 'like', "%{$query}%");
            }
        })->limit($buffer)->get();
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
