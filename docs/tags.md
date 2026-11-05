# Tags

Kinetix Tags adds **polymorphic tags** to any model — real, reusable tags stored
in their own table (unlike the form `TagsInput`, which just stores a string
array on the record). Tags autocomplete from the existing set, are deduped by
slug, and are **team-scoped automatically** when `kinetix.teams` is on.

<Screenshot name="tags" alt="Tag editor" />

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-tags-migrations
php artisan migrate
```

Enable the feature, add the `HasKinetixTags` trait to taggable models, and
allowlist them:

```php
'tags' => [
    'enabled' => env('KINETIX_TAGS_ENABLED', true),
],
```

```php
use Happones\Kinetix\Tags\HasKinetixTags;

class Post extends Model
{
    use HasKinetixTags;
}
```

```php
use Happones\Kinetix\Tags\KinetixTags;

// AppServiceProvider::boot()
KinetixTags::for([\App\Models\Post::class, \App\Models\Task::class]);
```

Only allowlisted models using the trait can be tagged from the endpoints.

---

## The component

Hand the page a **signed reference** to the record, minted on the server, and
mount `KinetixTags` with it:

```php
use Happones\Kinetix\Tags\KinetixTags;

return inertia('Posts/Show', [
    'post'           => $post->only('id', 'title'),
    'tags_reference' => KinetixTags::reference($post),
    // A read-only list: KinetixTags::reference($post, editable: false)
]);
```

```vue
<script setup lang="ts">
import KinetixTags from '@/components/kinetix/KinetixTags.vue';
</script>

<template>
    <KinetixTags :reference="tags_reference" />
</template>
```

The reference names exactly that record and whether it may be changed: one
minted with `editable: false` lists the tags but its sync answers 403. It is
bound to the user and team it was minted for and expires with
`kinetix.tables.token_ttl`, so mint it on every page render.

The legacy `taggable-type` + `:taggable-id` props still work while
`tags.require_reference` allows a raw type + id (see [Endpoints](#endpoints)).
Under teams they are refused by default.

It shows the current tags as removable chips, autocompletes from existing tags as
you type, and creates a new tag on **Enter**. Every change is synced to the
server, which returns the canonical set. `useKinetixTags(null, null, reference)`
(the reference as a ref, a getter or a string) exposes `{ tags, loading, load,
suggest, sync }` for a custom UI; `useKinetixTags(type, id)` is the legacy form. Strings are localized
(`tag_*`, en/es/fr/pt).

---

## Using tags in the backend

Once a model uses `HasKinetixTags`, the relation is available directly:

```php
$post->tags;                    // attached Tag models
$post->tags()->pluck('name');   // their names

app(\Happones\Kinetix\Tags\TagManager::class)->sync($post, ['Laravel', 'Vue']);
```

---

## Filtering a table by tag

Use `TagFilter` to add a multi-select of the existing tags that matches rows
carrying any of the selected ones (the table's model must use `HasKinetixTags`):

```php
use Happones\Kinetix\Tables\Filters\TagFilter;

Table::make(Post::query())->filters([TagFilter::make('tags')]);
```

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on):

| Method | Route                     | Name                    |
| ------ | ------------------------- | ----------------------- |
| `GET`  | `{prefix}/tags`           | `kinetix.tags.index`    |
| `GET`  | `{prefix}/tags/suggest`   | `kinetix.tags.suggest`  |
| `POST` | `{prefix}/tags/sync`      | `kinetix.tags.sync`     |

`index` / `sync` take `reference` (or the legacy `taggable_type` +
`taggable_id`); `suggest` takes `q`. A host `view` (read) / `update` (write)
policy on the model is honored.

A reference minted for another user or team, an expired one, a comments
reference, or a read-only one sent to `sync` answers 403. A raw type + id names
*any* record of an allowlisted class, so `tags.require_reference` decides when
it is accepted:

| `require_reference` | Raw type + id                                                            |
| ------------------- | ------------------------------------------------------------------------ |
| `null` (default)    | refused (403) when `kinetix.teams` is on; accepted otherwise             |
| `true`              | always refused                                                           |
| `false`             | accepted; under teams the model **must have a policy** (403 without one) |

Without teams a raw type + id behaves as before: the policy is honoured when
the model has one, and the record is allowed otherwise.
