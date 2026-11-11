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
- **Shared prop**: `kinetix_config.search = { min_chars: int, max_length: int|null }`
  (config only, no queries).

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
