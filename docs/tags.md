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

```vue
<script setup lang="ts">
import KinetixTags from '@/components/KinetixTags.vue';
</script>

<template>
    <KinetixTags taggable-type="App\Models\Post" :taggable-id="post.id" />
</template>
```

It shows the current tags as removable chips, autocompletes from existing tags as
you type, and creates a new tag on **Enter**. Every change is synced to the
server, which returns the canonical set. `useKinetixTags(type, id)` exposes
`{ tags, loading, load, suggest, sync }` for a custom UI. Strings are localized
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

`index` / `sync` take `taggable_type` + `taggable_id`; `suggest` takes `q`. A
host `view` (read) / `update` (write) policy on the model is honored.
