# Search

Every type-ahead surface in Kinetix (searchable selects, select and
multi-select table filters, checkbox lists, relation pickers, tag
suggestions, help search and the table toolbar search) talks to a small set
of lookup endpoints. They share one set of guardrails so a typing user, a
paste of a whole paragraph or a script cannot turn them into a load problem.

## Configuration

```php
// config/kinetix.php
'search' => [
    'max_length'        => env('KINETIX_SEARCH_MAX_LENGTH', 100),
    'min_chars'         => env('KINETIX_SEARCH_MIN_CHARS', 1),
    'throttle'          => env('KINETIX_SEARCH_THROTTLE', '120,1'),
    'relation_throttle' => env('KINETIX_RELATION_PICKER_THROTTLE', '60,1'),
    'concurrency'       => env('KINETIX_SEARCH_CONCURRENCY'),   // null = off
    'concurrency_ttl'   => 15,
    'timeout'           => env('KINETIX_SEARCH_TIMEOUT_MS'),    // null = off
    'team_scope'        => env('KINETIX_SEARCH_TEAM_SCOPE', true),
    'team_column'       => 'team_id',
    'schema_cache_ttl'  => 3600,
    'client'            => ['debounce' => 250, 'cache_ttl' => 30, 'cache_entries' => 100],
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `max_length` | `100` | Longest term, in characters. A longer term is **truncated**, never rejected. `null` removes the cap. |
| `min_chars` | `1` | Shortest non-empty term that runs a query in searchable selects, select and multi-select filters, checkbox lists and relation pickers. `1` keeps the behavior of earlier versions. Tags, help, the palette (`kinetix.spotlight.min_chars`) and the table search have their own rules. |
| `throttle` | `120,1` | `attempts,minutes` per user for select/filter search, tag suggestions and help search. `null` turns it off. |
| `relation_throttle` | `60,1` | The same for the relation manager attach and associate pickers. `null` turns it off. |
| `concurrency` | `null` | Lookups one user may have running at once. `null` = off. See [Concurrency](#concurrency). |
| `concurrency_ttl` | `15` | Seconds a concurrency slot survives a worker that crashed before freeing it. |
| `full_text.language` | `'english'` | Language of `SearchMode::FullText` on PostgreSQL. See [Search modes](#search-modes). |
| `timeout` | `null` | Statement budget, in milliseconds, for the select and relation picker queries. `null` = off. See [Query timeout](#query-timeout). |
| `team_scope` | `true` | With teams on, bounds remote selects and filters to the active team. See [Team scoping](#team-scoping). |
| `schema_cache_ttl` | `3600` | Seconds the cache store remembers whether a table has the team column. `null` = process memory only. |
| `team_column` | `team_id` | The column that holds a row's team when the model does not use `ScopedToTeam`. |
| `client.debounce` | `250` | Milliseconds the inputs wait after the last keystroke. |
| `client.cache_ttl` | `30` | Seconds the browser reuses an answer for the same term. `0` = no reuse. |
| `client.cache_entries` | `100` | Answers the browser keeps (least recently used go first). `0` = no reuse. |

The values are read on every request, so a config change applies to cached
routes too. Setting a throttle to the string `null` in `.env` disables it;
end-to-end suites that fire many lookups in a row can do exactly that.

## What a term goes through

Each endpoint normalizes the term with the same rules: anything that is not a
string is empty, control characters become a single space (repeated spaces collapse), the text is trimmed and cut
to `max_length` characters. The text is then matched with `LIKE` wildcards
escaped, so `%` and `_` are literal.

The page also receives the limits (`kinetix_config.search`, with
`min_chars`, `max_length`, `debounce`, `cache_ttl` and `cache_entries`) so the inputs cap what the user can type and
explain the minimum. They are configuration only and cost no queries.

## Short terms

When `min_chars` is above 1, in those inputs a non-empty term shorter than it does not query
the database. The lookup answers:

```json
{ "options": [], "meta": { "below_min": true, "min_chars": 3 } }
```

An empty term is not "short": it still returns the initial list. The inputs
show a hint ("Type at least 3 characters to search.") instead of an empty
result.

## Rejections and throttling

A search whose descriptor cannot be used answers `403`. The message is
unchanged and the body now carries a `code` the client can branch on:

| `code` | Meaning |
| --- | --- |
| `descriptor_expired` | The page's search token expired (or predates tokens). Reload the page. |
| `descriptor_forbidden` | The token was minted for another user, team or purpose. |

A throttled lookup answers `429` with the same body every Kinetix throttle
uses:

```json
{ "message": "Too many attempts. Try again in 12 seconds.", "code": "throttled", "retry_after": 12 }
```

along with a `Retry-After` header. The budget is per user (by IP for a guest)
and each family counts apart: lookups never spend the password or export
budgets.

## Team scoping

With teams on (`kinetix.teams`), every `relationship()` select (searchable or
not, also `CheckboxList` and `Radio`), remote `searchUsing()` select, select
filter and multi-select filter is bounded to the **active team** without you
declaring anything. The same bound applies to the preloaded options, the search
endpoint, the lookup of the selected label, the strict-options validation and
the preloaded options of `SelectFilter::relationship()`. The team is the
membership-checked `KinetixTeams::currentTeamKey()`: the `{current_team}`
segment of the URL when there is one (a segment the user does not belong to
answers 404), else the user's current team. The endpoint still requires the
signed descriptor minted for that user and team, so a token from team A replayed
under team B's URL answers 403.

The bound is chosen in this order:

1. Teams off, `search.team_scope` false, or the field called `->withoutTeamScope()`: no bound.
2. The field's own `searchScope()` names the team column: yours wins, nothing is added.
3. A model using `ScopedToTeam` whose module is team-scoped: the team's rows (`forCurrentTeam()`), plus the `NULL` ones when the model declares them shared (`kinetixTeamRowsIncludeGlobal()` returns true: announcements and mail templates do) or the field calls `->teamScopeIncludesGlobal()`. A model whose module is single-tenant is not bounded.
4. The configured spatie role model with `permission.teams` on: the team's roles plus the global ones (also with no team resolved), the same visibility as the roles screen.
5. The `search.team_column` column when the table has it: the team's rows **only**; with `->teamScopeIncludesGlobal()` also the rows whose team is `NULL`.
6. Otherwise (a shared catalog such as countries or currencies): no bound.

It fails closed: when no team resolves, nothing matches (the role model keeps
its global roles, like the roles screen). Tables that mix team rows and shared
`NULL` rows (a host catalog of default templates, a host model using
`ScopedToTeam`) hide the shared rows until the model declares them with
`kinetixTeamRowsIncludeGlobal()` or the field adds `->teamScopeIncludesGlobal()`.
A closure or class modifier is applied inside its own group (its `whereNot`
negations kept), so an `orWhere`, even a leading one, never widens the team
bound. A `limit()` in the modifier never raises the options cap.

The "has the column" check costs one schema query per table the first time it
is needed. The answer is then kept in the cache store for
`kinetix.search.schema_cache_ttl` seconds (default 3600; `null` = process memory
only) and in the process (cleared between requests and jobs), and forgotten when
a migration run finishes (`MigrationsEnded`) on that node. On a multi-node deploy,
the other nodes pick a new column up when the entry expires, or call
`SearchScope::flush()` once after migrating.

Opt a field out when it is meant to span teams (a platform console):

```php
Select::make('team_id')
    ->searchUsing(Team::class)
    ->withoutTeamScope();          // also on SelectFilter and CheckboxList
```

The opt-outs are sealed into the descriptor, so the browser can't flip them.
Bound a cross-team picker yourself with `searchScope()` or a query modifier.

The relation manager attach and associate pickers (and the ids those endpoints
accept) use the manager's `getRelatedQuery()`: the related resource's
`getEloquentQuery()` when you override it, else the model query bounded to the
active team with the same rules. If your resource (or a base resource)
overrides `getEloquentQuery()`, bound it yourself:
`SearchScope::bindToTeam($query, [])` applies these same rules (a public,
stable helper) and `KinetixTeams::currentTeamKey()` gives you the team. A picker of users that
belongs to a team through a membership pivot (no `team_id` on the users table)
is not bounded automatically: add a query modifier that joins the pivot.

::: warning Upgrade note
Apps with teams on and a model with a `team_id` column now only see the active
team's rows in these selects, filters and relation pickers. A deliberately
cross-team picker needs `->withoutTeamScope()`, or set
`KINETIX_SEARCH_TEAM_SCOPE=false`; a mixed table needs
`->teamScopeIncludesGlobal()`. Apps that replaced the search controller, or
patched `SelectFilter` or the relation managers, only to bound by team can drop
those replacements.
:::

## Scoping a select filter

A searchable `SelectFilter` or `MultiSelectFilter` queries its model
directly, so, like a searchable `Select` field, it is only as private as the
model's global scopes. Bound it with `searchScope()`:

```php
SelectFilter::make('project_id')
    ->searchUsing(Project::class, 'name', ['name'])
    ->searchScope(['archived' => false]);
```

`searchScope()` takes plain equality constraints (`null` becomes
`whereNull`). For anything more, pass an invokable class as the fifth argument
of `searchUsing()`; it receives the query builder:

```php
SelectFilter::make('project_id')
    ->searchUsing(Project::class, 'name', ['name'], 'id', OnlyActiveProjects::class);
```

It must be the class-string of an invokable class (anything else throws `InvalidArgumentException` when you define the filter), never a closure. Its `where` clauses are nested in their own group, so an `orWhere` cannot reach past the scope constraints, while its joins and ordering still apply. The descriptor travels through
the browser, sealed, and is resolved back to an object on the server.

The scope and modifier are sealed into the filter's token and applied twice:
by the search endpoint, and when the filter looks up the **labels of the
values already selected** in the request. A forged `filters[project_id]=42`
naming another tenant's row therefore never gets a label.

::: warning Upgrade note
Add `searchScope()` to every `SelectFilter::searchUsing()` whose model has no
global tenant scope. Before this release the selected-label lookup applied no
bound at all.
:::

## Cancelling stale answers

The inputs cancel the request a newer keystroke supersedes, so a slow answer
to an older term can never replace the list for the term on screen.

## Concurrency

A type-ahead cancels the request a newer keystroke supersedes, but PHP keeps
running the cancelled one. A fast typist can therefore stack slow queries on
the database even under the per-minute throttle. Set a per-user cap to bound
that:

```dotenv
KINETIX_SEARCH_CONCURRENCY=3
```

Each user gets that many lock slots; a lookup takes a free one for the
duration of the request and frees it afterwards (also when it fails). With no
slot free the lookup answers a `429` and the browser retries the latest term
once:

```json
{ "message": "The search is busy. Try again in a moment.", "code": "busy", "retry_after": 1 }
```

It covers select and filter search, tag suggestions, help search and the
relation pickers, runs after their throttle, and counts the user (the client
IP for a guest). The cap needs a cache store that supports locks (Redis is
recommended). On a store without locks it does nothing, and the `database`
store works but takes database locks on every lookup; `php artisan
kinetix:doctor` warns about both, and about the `array` store, which lives in
one process and so does nothing under PHP-FPM.

A slot a crashed worker never freed expires after `concurrency_ttl` seconds.
Keep it at or above `timeout` (or above your slowest lookup when there is
none): a slot that expires while its lookup still runs lets the user start
another beside it.

A guest is counted by IP address. Behind a load balancer or CDN configure
Laravel's trusted proxies (`TrustProxies`) so `$request->ip()` is the
visitor's, not the proxy's; otherwise every guest shares one set of slots.

## Query timeout

```dotenv
KINETIX_SEARCH_TIMEOUT_MS=1500
```

Gives the searchable select and relation picker queries a time budget. A
query that runs out is cancelled by the database and the lookup answers an
empty list the input explains, instead of holding a worker:

```json
{ "options": [], "meta": { "timed_out": true } }
```

A timed-out answer is never reused by the browser cache. The budget lives in
the database session only while the lookup runs and is restored afterwards.

| Database | Mechanism |
| --- | --- |
| MySQL | `max_execution_time` (SELECT statements) |
| MariaDB | `max_statement_time` |
| PostgreSQL | `SET LOCAL statement_timeout` in its own transaction; skipped when the lookup already runs inside your transaction |
| SQLite, SQL Server | none, the lookup just runs |

Kinetix sets the limit on the connection the lookup reads from, and puts the
previous value back afterwards. If the limit cannot be set (a restricted
database user, say), the lookup runs without a budget rather than failing.

::: warning Read/write split
MySQL and MariaDB limit the **read** connection, so the replica is protected.
PostgreSQL can only limit a statement inside a transaction, and a transaction
runs on the write connection: with a read/write split, budgeted lookups go to
the **primary**. Leave `timeout` off for PostgreSQL replicas you want to
keep lookups on. `php artisan kinetix:doctor` warns when the default
connection is split. The budget follows the default connection.
:::

::: tip Tables have their own budget
The table's count, page and aggregate queries are budgeted separately, with
`kinetix.tables.query_timeout` or `Table::queryTimeout()`. See
[Query timeout](/tables#query-timeout) and, for a total that is not recounted on
every page, [Cached totals](/tables#cached-totals-—-cachedtotal).
:::

## Search modes

By default a term is matched with `LIKE '%term%'` (**contains**): it finds the
term anywhere, and no B-tree index can serve it, so the database reads every row.
On a large table pick a mode that fits the column:

```php
use Happones\Kinetix\Query\SearchMode;

Table::make(Customer::query())
    ->searchMode(SearchMode::Prefix)                                   // every searchable column
    ->columns([
        TextColumn::make('name')->searchable(),                        // prefix
        TextColumn::make('code')->searchable(mode: SearchMode::Exact), // exact
        TextColumn::make('bio')->searchable(mode: SearchMode::FullText),
    ]);

Select::make('customer_id')->searchUsing(Customer::class)->searchMode(SearchMode::Prefix);
SelectFilter::make('customer_id')->searchUsing(Customer::class)->searchMode(SearchMode::Prefix);
```

| Mode | Predicate | Index it can use |
| --- | --- | --- |
| `Contains` (default) | `LIKE '%term%'` | none (a trigram / full-text index at best) |
| `Prefix` | `LIKE 'term%'` | B-tree on MySQL and MariaDB; `text_pattern_ops` on PostgreSQL (`CREATE INDEX ON t (col text_pattern_ops)`) unless the database uses the C collation |
| `Exact` | `col = term` | any B-tree / unique index |
| `FullText` | MySQL / MariaDB `MATCH … AGAINST` (natural language), PostgreSQL `to_tsvector @@ plainto_tsquery` | `FULLTEXT` index; GIN on `to_tsvector('english', col)` |

Wildcards in the term (`%`, `_`) are always literal, in every mode.

- **Prefix only pays off when every searchable column is index-friendly.** The
  columns are OR-ed together, so one `Contains` column (or a column without an
  index) makes the whole query scan. Give each a B-tree index, or keep it out of
  the search.
- **Exact has a type guard.** A term that cannot be a value of the column is
  skipped, not sent to the engine: letters or a number past `bigint` against an
  integer key or cast, a non-finite number against a float / decimal cast, a
  date that is not strictly `Y-m-d`, `Y-m-d H:i` or `Y-m-d H:i:s` (`2026`,
  `monday`, `2026-02-30` are refused), a malformed UUID or ULID on a `HasUuids` /
  `HasUlids` key. An exact search on a datetime matches that exact timestamp
  (midnight for a date-only term); on a `date` cast it takes no time of day. On
  PostgreSQL the integer primary key is bound as `bigint`, so a number past
  `int4` simply does not match; any other integer column is bounded to int4 by
  the guard (a `smallint` column can still overflow past 32767: use
  `searchable(using:)` for one). If every column is skipped the search matches **nothing** (it
  does not fall back to everything); a table with no searchable column at all
  still ignores the search, as before. A column written `table.column` with the
  model's own table is guarded like a plain one; a column of another table
  (a pivot column) is not guarded, and a relation column (`author.name`) is
  guarded by the related model. Passing anything but a `SearchMode` or a closure
  as a column's spec throws `InvalidArgumentException`.
- **Full text** uses the engine's natural-language search only (no boolean
  operators from the user). It needs the index to exist and uses it only with
  the same columns: on MySQL and MariaDB the `MATCH` columns must be exactly the
  index's columns, so a multi-column `FULLTEXT` index cannot serve a single
  column. Words shorter than the engine's minimum token size
  (`innodb_ft_min_token_size`, 3 by default) and stopwords never match; on
  PostgreSQL the language comes from `kinetix.search.full_text.language`
  (`KINETIX_SEARCH_FULL_TEXT_LANGUAGE`, default `english`) and must match the
  index expression. On MySQL and MariaDB a column without a `FULLTEXT` index
  is an error (1191, a 500), not a slow scan: create the index before using the
  mode. SQLite and SQL Server fall back to `Contains`. Combine it
  with `->searchMinLength(3)` so short terms do not query at all.
- **PostgreSQL and non-text columns.** `LIKE` does not exist for numbers, dates
  or native `uuid` columns there, so `Contains` and `Prefix` on a key or a column
  with a numeric / date cast compare `cast(col as text)`. Text columns are never
  cast, so their indexes stay usable. Timestamps (`created_at`, `updated_at`)
  and declared dates count as dates. Declare the cast (or the key type) on the
  model: Kinetix does not read the database schema, so an integer column
  without a cast is not detected and still fails on PostgreSQL.
- **A custom match.** `->searchable(using: fn (Builder $query, string $term) =>
  $query->where('sku', $term))` replaces the comparison for one column, like
  `sortable(using:)`. The closure runs inside the search's grouped OR, so an
  `orWhere` in it cannot widen past the table's own constraints (a tenant
  filter), but it must **bind the term**: never concatenate it into SQL. Only its
  `where` constraints count: joins and orders are dropped by the nested group, so
  reach other tables with `whereHas`, `whereExists` or `whereIn` subqueries. A
  closure that adds no constraint matches nothing.
- **Select and filter tokens seal the mode.** The browser cannot choose it; a
  token minted before this existed means `Contains`.
- **Teams.** A mode changes only how the term is compared. It adds no cache key
  and no data, and the team scope of remote search, the table's base query and
  every `ScopedToTeam` constraint apply as before.

## Table search minimum

```php
Table::make($query)->searchMinLength(3);
```

The toolbar search waits until the term has that many characters before it
reloads the table and shows a hint meanwhile; clearing the box always
reloads. It is sent to the page as `searchMinLength` (`0`, the default, means
every keystroke). A request that carries a shorter `search` is still
answered: the minimum shapes the toolbar, it is not a server rule.

## Client composable

Every typed lookup in Kinetix goes through `useKinetixSearch`. It debounces,
lets the latest answer win, joins an identical in-flight query, answers
repeats from a small cache, never sends a term below the minimum and turns
throttling, timeouts and expired tokens into a status instead of an
exception.

```ts
import { useKinetixSearch } from '@/composables/useKinetixSearch';

const search = useKinetixSearch<Option[]>({
    fetcher: async (query, { signal }) => {
        const data = await kinetixFetch<{ options: Option[]; meta?: KinetixLookupMeta }>(
            `/${prefix}/forms/search`,
            { method: 'POST', body: { token, q: query }, signal },
        );

        return { value: data?.options ?? [], meta: data?.meta };
    },
    minChars: () => limits.minChars.value,
    cacheKey: () => `${prefix}:forms.search:${token}`,
});

void search.search(input.value); // debounced; { immediate: true } to run now
```

Options: `fetcher` (required), `debounce` (default
`kinetix_config.search.debounce`), `minChars` (number or getter, default 0; an
empty term is the initial list and is never too short), `maxLength`,
`cacheKey` (endpoint + token; null or absent = no cache), `cacheTtl` (ms), `retryOnThrottle`
(default true) and `onSettled`.

Returns `results`, `status`, `loading`, `message`, `retryAfter`, `retrying`,
`meta`, `search(term, { immediate?, force? })`, `cancel()`, `reset()` and
`invalidate()`. `search()` never rejects; it resolves to the answer, or `null`
when it was superseded, skipped or failed.

| Status | Meaning |
| --- | --- |
| `idle` | Nothing asked, or cancelled. |
| `debouncing` | Waiting for typing to pause. |
| `loading` | A request is in flight. |
| `success` | Answered. |
| `tooShort` | Below the minimum (client check or `meta.below_min`); nothing searched. |
| `throttled` | `429` (`throttled` or `busy`): the last results stay and the latest term is retried once after `retry_after` (up to 10 s). |
| `timedOut` | `meta.timed_out`: partial or empty, never cached. |
| `expired` | `descriptor_expired`: the page is out of date. |
| `error` | Any other failure; `message` is the server's own text when it sent one. |

### Cache

Answers are kept in memory for `cache_ttl` seconds, at most `cache_entries`
of them (least recently used out). Who is asking is added to every key for
you: the user, an impersonation, the team (route prefix) and the language. A
navigation that changes any of them (logout and login, impersonation start or
stop, another team) empties the whole cache, so a second user in the same tab
never sees the first one's answers. Your `cacheKey` only names the endpoint
and the signed token; a source you cannot key that way returns `null` and is
never cached. The cache is off during SSR. After a write that changes what a
lookup offers, call `search.invalidate()` or
`clearKinetixSearchCache(namespace)` (nested `namespace:` entries go too; no
argument clears all). Kinetix does this after attach, associate, detach and
dissociate, and after a tag sync. In your own specs, call
`clearKinetixSearchCache()` between tests.

### Announcements

`useKinetixSearchAnnouncer({ count, tooShort? })` returns an `onSettled`
callback that tells screen readers the result count, that the search is
retrying, or that it timed out, once per settled search and never per
keystroke. A throttle that ran out of retries is not announced (the control's
own alert says it), and a search started with `{ silent: true }` (an initial
load) is not announced either. Pass `tooShort: false` where the control already shows the hint in
its own live region. Lists also set `aria-busy` while loading, and inputs are
never disabled.

### Table search

The toolbar skips the visit when the term equals what the table shows (or is
already on its way) and waits for `searchMinLength` characters while showing
the hint; clearing the box always reloads.

### Writing your own lookup

A debounce timer that ends in a request must use `useKinetixSearch` (or at
least `createKinetixLatest`), or an older answer can paint over a newer one.
In Kinetix, `tests/js/lookupRequests.spec.ts` enforces it.
