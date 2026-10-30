<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use Happones\Kinetix\Query\KinetixQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Locations read from three tables — countries, states, cities — linked by
 * foreign keys: the shape of nnjeim/world, altwaireb/laravel-countries and
 * most hand-rolled catalogs. Every table and column comes from the provider's
 * config entry:
 *
 *     'world' => [
 *         'driver'     => 'database',
 *         'connection' => null,
 *         'countries'  => ['table' => 'countries', 'key' => 'id', 'code' => 'iso2', 'name' => 'name', 'active' => 'status'],
 *         'states'     => ['table' => 'states', 'key' => 'id', 'name' => 'name', 'country' => 'country_id'],
 *         'cities'     => ['table' => 'cities', 'key' => 'id', 'name' => 'name', 'country' => 'country_id', 'state' => 'state_id'],
 *     ],
 *
 * `active` (a boolean column) and `soft_deletes` (true, or the column name)
 * leave disabled rows out, per table. Queries go through the query builder
 * (no models), filtered on the foreign keys — index `states.country_id`,
 * `cities.state_id` and `cities.country_id` if the package didn't.
 */
class DatabaseLocationProvider implements LocationProvider
{
    /**
     * @var array<string, array{id: int|string, name: string}>|null
     */
    private ?array $countryRows = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = []) {}

    public function countries(): array
    {
        return array_map(fn (array $row): string => $row['name'], $this->countryRows());
    }

    public function states(string $country, string $search = '', int $limit = 1000): array
    {
        $countryId = $this->countryId($country);

        if ($countryId === null) {
            return [];
        }

        $query = $this->table('states')->where($this->column('states', 'country', 'country_id'), $countryId);

        return $this->options('states', $query, $search, $limit);
    }

    public function cities(string $country, ?string $state, string $search = '', int $limit = 50): array
    {
        $countryId = $this->countryId($country);

        if ($countryId === null) {
            return [];
        }

        $query = $state !== null
            ? $this->table('cities')->where($this->column('cities', 'state', 'state_id'), $state)
            : $this->table('cities')->where($this->column('cities', 'country', 'country_id'), $countryId);

        // A state from another country lists nothing.
        if ($state !== null && $this->stateLabel($country, $state) === null) {
            return [];
        }

        return $this->options('cities', $query, $search, $limit);
    }

    public function stateLabel(string $country, string $state): ?string
    {
        $countryId = $this->countryId($country);

        if ($countryId === null) {
            return null;
        }

        $name = $this->table('states')
            ->where($this->column('states', 'key', 'id'), $state)
            ->where($this->column('states', 'country', 'country_id'), $countryId)
            ->value($this->column('states', 'name', 'name'));

        return $name !== null ? (string) $name : null;
    }

    public function cityLabel(string $country, ?string $state, string $city): ?string
    {
        $countryId = $this->countryId($country);

        if ($countryId === null) {
            return null;
        }

        $query = $this->table('cities')
            ->where($this->column('cities', 'key', 'id'), $city)
            ->where($this->column('cities', 'country', 'country_id'), $countryId);

        if ($state !== null) {
            $query->where($this->column('cities', 'state', 'state_id'), $state);
        }

        $name = $query->value($this->column('cities', 'name', 'name'));

        return $name !== null ? (string) $name : null;
    }

    /**
     * The countries table, once per instance: 250 rows, needed to turn a code
     * into the id the other tables reference.
     *
     * @return array<string, array{id: int|string, name: string}>
     */
    protected function countryRows(): array
    {
        if ($this->countryRows !== null) {
            return $this->countryRows;
        }

        $key  = $this->column('countries', 'key', 'id');
        $code = $this->column('countries', 'code', 'iso2');
        $name = $this->column('countries', 'name', 'name');

        $rows = [];

        foreach ($this->table('countries')->orderBy($name)->get([$key, $code, $name]) as $row) {
            $rows[strtoupper((string) $row->{$code})] = ['id' => $row->{$key}, 'name' => (string) $row->{$name}];
        }

        return $this->countryRows = $rows;
    }

    protected function countryId(string $country): int|string|null
    {
        return $this->countryRows()[strtoupper($country)]['id'] ?? null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function options(string $level, Builder $query, string $search, int $limit): array
    {
        $key  = $this->column($level, 'key', 'id');
        $name = $this->column($level, 'name', 'name');

        $this->whereNameContains($query, $name, $search);

        $options = [];

        foreach ($query->orderBy($name)->limit(max(1, $limit))->get([$key, $name]) as $row) {
            $options[] = ['value' => (string) $row->{$key}, 'label' => (string) $row->{$name}];
        }

        return $options;
    }

    /**
     * Rows whose $column contains $search (all when it is blank). Wildcards
     * in the term are escaped (with KinetixQuery's `!`): a bare `%` must not
     * match every row.
     */
    protected function whereNameContains(Builder $query, string $column, string $search): Builder
    {
        if (trim($search) === '') {
            return $query;
        }

        return $query->whereRaw(
            $query->getGrammar()->wrap($column)." like ? escape '!'",
            ['%'.KinetixQuery::escapeLike(trim($search)).'%'],
        );
    }

    /**
     * A table's base query, with its disabled rows left out.
     */
    protected function table(string $level): Builder
    {
        $config = $this->levelConfig($level);
        $query  = DB::connection($this->config['connection'] ?? null)->table((string) ($config['table'] ?? $level));

        if (is_string($config['active'] ?? null)) {
            $query->where($config['active'], true);
        }

        $softDeletes = $config['soft_deletes'] ?? false;

        if ($softDeletes === true || is_string($softDeletes)) {
            $query->whereNull(is_string($softDeletes) ? $softDeletes : 'deleted_at');
        }

        return $query;
    }

    protected function column(string $level, string $column, string $default): string
    {
        return (string) ($this->levelConfig($level)[$column] ?? $default);
    }

    /**
     * @return array<string, mixed>
     */
    protected function levelConfig(string $level): array
    {
        $config = $this->config[$level] ?? [];

        return is_array($config) ? $config : [];
    }
}
