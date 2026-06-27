# Comments

Kinetix Comments adds **polymorphic, threaded comments** to any model — posts,
tasks, invoices, anything. Each authenticated user can read and post comments on
records they may view, reply one level deep, and **edit or delete only their
own**. A host `view` policy on the model is honored automatically.

<Screenshot name="comments" alt="Threaded comments" />

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-comments-migrations
php artisan migrate
```

Enable the feature and declare which models accept comments (their morph type or
class) from a service provider — only these can be commented on:

```php
'comments' => [
    'enabled' => env('KINETIX_COMMENTS_ENABLED', true),
],
```

```php
use Happones\Kinetix\Comments\KinetixComments;

// AppServiceProvider::boot()
KinetixComments::for([\App\Models\Post::class, \App\Models\Task::class]);
```

The allowlist keeps the public endpoints from reaching arbitrary records. If you
use a [morph map](https://laravel.com/docs/eloquent-relationships#custom-polymorphic-types),
pass either the class or its alias.

---

## The component

Mount `KinetixComments` with the model's morph type and id:

```vue
<script setup lang="ts">
import KinetixComments from "@/components/KinetixComments.vue";
</script>

<template>
  <KinetixComments commentable-type="App\Models\Post" :commentable-id="post.id" />
</template>
```

It renders a composer, the threaded list (with author avatars/initials and
relative timestamps), inline **Reply**, and **Edit** / **Delete** on the user's
own comments. The server returns the full tree after each change, so the list
stays authoritative. `useKinetixComments(type, id)` exposes
`{ comments, loading, load, post, edit, remove }` for a custom UI. All strings
are localized (`comment_*`, en/es/fr/pt).

---

## Authorization

- **Read / post**: any authenticated user who may view the commentable. When the
  model has a policy, Kinetix checks the `view` ability before allowing access.
- **Edit / delete**: restricted to the comment's author (HTTP 403 otherwise).
- **Allowlist**: a `commentable_type` that isn't registered returns 404.

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on):

| Method   | Route                       | Name                     |
| -------- | --------------------------- | ------------------------ |
| `GET`    | `{prefix}/comments`         | `kinetix.comments.index` |
| `POST`   | `{prefix}/comments`         | `kinetix.comments.store` |
| `PUT`    | `{prefix}/comments/{id}`    | `kinetix.comments.update` |
| `DELETE` | `{prefix}/comments/{id}`    | `kinetix.comments.destroy` |

`index` / `store` take `commentable_type` + `commentable_id` (and `body` /
`parent_id` for `store`); `store` returns the refreshed tree. Deleting a
top-level comment removes its replies too.
