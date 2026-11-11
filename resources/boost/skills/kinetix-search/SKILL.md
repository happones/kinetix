---
name: kinetix-search
description: "Shared guardrails of every Kinetix type-ahead lookup: term length cap, minimum characters, per-user throttles, 403 codes, below_min answers and SelectFilter scoping. Activates when configuring kinetix.search, handling lookup errors, or scoping a searchable select or filter."
license: MIT
metadata:
  author: happones
---

# Kinetix Search Development

## When to Apply

Activate this skill when:
- Tuning `kinetix.search.*` (term cap, minimum characters, throttles).
- Writing a client or test that calls a lookup endpoint (searchable `Select`,
  `SelectFilter`/`MultiSelectFilter`, relation pickers, tag suggestions, help search).
- Scoping a searchable select or select filter in a multi-tenant app.
- Adding a new search endpoint that reads a free-text term.

## Documentation

For full details, reference `docs/search.md` (published at https://happones.github.io/kinetix/search).

## Configuration

```php
'search' => [
    'max_length'        => env('KINETIX_SEARCH_MAX_LENGTH', 100),       // null = uncapped; truncates, never rejects
    'min_chars'         => env('KINETIX_SEARCH_MIN_CHARS', 1),          // 1 = every term queries
    'throttle'          => env('KINETIX_SEARCH_THROTTLE', '120,1'),     // forms/search, tags/suggest, help/search
    'relation_throttle' => env('KINETIX_RELATION_PICKER_THROTTLE', '60,1'), // relations/attachable|associable
    'concurrency'       => env('KINETIX_SEARCH_CONCURRENCY'),   // null = off; in-flight lookups per user, needs a lock-capable cache (Redis)
    'concurrency_ttl'   => 15,                                   // seconds a slot survives a crashed worker
    'timeout'           => env('KINETIX_SEARCH_TIMEOUT_MS'),    // null = off; ms statement budget (MySQL, MariaDB, PostgreSQL)
    'client'            => ['debounce' => 250, 'cache_ttl' => 30, 'cache_entries' => 100], // shared to the browser
],
```

`null` turns a throttle off (the `.env` string `null` works too), which is the
switch for E2E suites. Values are read per request, so they apply to cached
routes.

## Contracts

- **Term**: pass every free-text request value through
  `Happones\Kinetix\Query\SearchTerm::from($request->query('q'))` (string only,
  control characters become one space, trimmed, cut to `max_length`). Never read the raw
  input into `KinetixQuery::search()`.
- **Short term**: `SearchTerm::isBelowMinimum($term)` is true only for a
  non-empty term shorter than `min_chars`; answer
  `{ options: [], meta: { below_min: true, min_chars: N } }` without querying.
- **403** from the search endpoint: `{ message, code }` with
  `code: descriptor_expired | descriptor_forbidden`.
- **429**: the standard throttle body `{ message, code: 'throttled', retry_after }`.
- **Shared prop**: `kinetix_config.search = { min_chars, max_length: int|null, debounce, cache_ttl, cache_entries }`
  (config only, no queries; `Query\SearchTerm::clientSettings()` builds the last three).
- **Busy 429**: `Support\KinetixLookupSlots` (alias `kinetix.lookup-slots`, after the
  throttle on every lookup route) answers `{ message, code: 'busy', retry_after: 1 }`
  when the user's `search.concurrency` slots are all taken. Plain cache locks; a store
  without locks makes it a no-op. Add it to a new lookup route with
  `->middleware([KinetixThrottle::middleware('kinetix-search'), KinetixLookupSlots::ALIAS])`.
- **Timeout**: run the lookup's `get()` through
  `Query\QueryBudget::run($model->getConnection(), QueryBudget::configured(), fn () => ...)`
  and catch `QueryBudgetExceeded` to answer `200 { options: [], meta: { timed_out: true } }`.
  The session limit is restored in `finally`; PostgreSQL skips the budget inside a
  caller's transaction; SQLite/SQL Server never time out.
- **Table**: `Table::searchMinLength(int)` ships `TableData.searchMinLength` (0 = every
  keystroke); a UI hint only, never enforced server-side.

## Scoping

A searchable `SelectFilter` / `MultiSelectFilter` queries its model directly:

```php
SelectFilter::make('project_id')
    ->searchUsing(Project::class, 'name', ['name'], 'id', OnlyActiveProjects::class) // 5th arg: invokable class-string
    ->searchScope(['team_id' => $team->getKey()]);
```

The scope and modifier are sealed into the token and applied by the endpoint
AND by the selected-label lookup (`resolveSelectedOptions()`), so a forged
filter value never yields another tenant's label. The modifier must be an invokable class-string (else `InvalidArgumentException` at definition time); it is applied as a query scope, so its wheres are nested in their own group (`orWhere` can't leak) while its joins/orders still apply. Closures are not allowed:
the descriptor round-trips through the browser.

## Adding a throttled lookup

Add the route to the `kinetix-search` (or `kinetix-relation-picker`) limiter with
`KinetixThrottle::middleware('kinetix-search')`; limiters are registered
unconditionally so `route:cache` stays safe.

## Client composable

- Write every typed lookup with `useKinetixSearch` (`fetcher(query, { signal })` returns `{ value, meta }`); it debounces, lets the latest answer win, dedupes, caches and never rejects. Read `status` (`tooShort`, `throttled`, `timedOut`, `expired`, `error`), `message` and `results`.
- `cacheKey` names the endpoint and the signed token (who is asking is added for you and a change of it clears the cache); return null for a source you cannot key that way. After a write that changes the options, call `invalidate()` or `clearKinetixSearchCache(namespace)`; clear it between tests too.
- A 429 (`throttled` or `busy`) keeps the last results and retries the latest term once; show the retry as a note, not an error.
- Announce with `useKinetixSearchAnnouncer` (once per settled search), set `aria-busy` while loading, never disable the input.
- A debounce timer that ends in a request without `useKinetixSearch` or `createKinetixLatest` is a bug; Kinetix's `tests/js/lookupRequests.spec.ts` enforces it.
- Tables: `Table::searchMinLength()` delays the toolbar reload (clearing always reloads); the same term is never re-requested.
