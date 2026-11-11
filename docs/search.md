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
],
```

| Key | Default | Meaning |
| --- | --- | --- |
| `max_length` | `100` | Longest term, in characters. A longer term is **truncated**, never rejected. `null` removes the cap. |
| `min_chars` | `1` | Shortest non-empty term that runs a query in searchable selects, select and multi-select filters, checkbox lists and relation pickers. `1` keeps the behavior of earlier versions. Tags, help, the palette (`kinetix.spotlight.min_chars`) and the table search have their own rules. |
| `throttle` | `120,1` | `attempts,minutes` per user for select/filter search, tag suggestions and help search. `null` turns it off. |
| `relation_throttle` | `60,1` | The same for the relation manager attach and associate pickers. `null` turns it off. |

The values are read on every request, so a config change applies to cached
routes too. Setting a throttle to the string `null` in `.env` disables it;
end-to-end suites that fire many lookups in a row can do exactly that.

## What a term goes through

Each endpoint normalizes the term with the same rules: anything that is not a
string is empty, control characters become a single space (repeated spaces collapse), the text is trimmed and cut
to `max_length` characters. The text is then matched with `LIKE` wildcards
escaped, so `%` and `_` are literal.

The page also receives the limits (`kinetix_config.search`, with
`min_chars` and `max_length`) so the inputs cap what the user can type and
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

## Scoping a select filter

A searchable `SelectFilter` or `MultiSelectFilter` queries its model
directly, so, like a searchable `Select` field, it is only as private as the
model's global scopes. Bound it with `searchScope()`:

```php
SelectFilter::make('project_id')
    ->searchUsing(Project::class, 'name', ['name'])
    ->searchScope(['team_id' => $request->user()->currentTeam->getKey()]);
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
