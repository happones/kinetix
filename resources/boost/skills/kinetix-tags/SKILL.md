---
name: kinetix-tags
description: "Polymorphic tags on any model: real reusable tags (vs the TagsInput string array), autocomplete + create, dedup by slug, team-scoped, plus a TagFilter for tables. Activates when adding tagging to a model."
license: MIT
metadata:
  author: happones
---

# Kinetix Tags Development

## When to Apply

Activate this skill when:
- Adding reusable polymorphic tags to a model (NOT the form `TagsInput`, which
  just stores a string array on the record).
- Mounting `<KinetixTags>` / using `useKinetixTags`.
- Filtering a table by tag with `TagFilter`.

## Documentation

For full details, reference `docs/tags.md` (published at https://happones.github.io/kinetix/tags).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-tags-migrations
php artisan migrate
```

```php
'tags' => ['enabled' => env('KINETIX_TAGS_ENABLED', false)],
```

```php
use Happones\Kinetix\Tags\HasKinetixTags;

class Post extends Model { use HasKinetixTags; }
```

```php
use Happones\Kinetix\Tags\KinetixTags;

// AppServiceProvider::boot() — allowlist taggable models
KinetixTags::for([\App\Models\Post::class, \App\Models\Task::class]);
```

Tags are deduped by slug and team-scoped automatically when `kinetix.teams` is on.

---

## Backend

- `HasKinetixTags` trait → `$post->tags` (Tag models) / `$post->tags()->pluck('name')`.
- `TagManager`: `for($taggable)`, `suggest($q, $teamId)`, `sync($taggable, $names, $teamId)`
  (find-or-create), `all($teamId)`.
- `TagController` (self-service, team-aware `{prefix}/tags`): `index`, `suggest`,
  `sync` — taggable resolved + allowlisted via `TagRegistry`; host `view`/`update`
  policy honored.
- `TagFilter` (table): multi-select of existing tags → `whereHas('tags')`.

---

## Frontend

```vue
<KinetixTags taggable-type="App\Models\Post" :taggable-id="post.id" />
```

Removable chips + autocomplete from existing tags + create-on-Enter; syncs each
change. `useKinetixTags(type, id)` → `{ tags, loading, load, suggest, sync }`.
i18n `tag_*` (en/es/fr/pt).

## UUID / ULID Host Models

This feature's migration builds `team_id`, plus the `taggable_id` morph id with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
