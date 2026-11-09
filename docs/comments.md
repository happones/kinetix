# Comments

Kinetix Comments adds **polymorphic, threaded comments** to any model — posts,
tasks, invoices, anything. Each authenticated user can read and post comments on
records they may view, reply one level deep, and **edit or delete only their
own**. A host `view` policy on the model is honored automatically.

<Screenshot name="comments" alt="Threaded comments" />

::: tip Long threads virtualize automatically
When a thread grows past ~40 top-level comments, the list windows its rows via
`@tanstack/vue-virtual` (only the visible comments render). Shorter threads
render in full and never download it. `@tanstack/vue-virtual` is a required peer,
installed by `php artisan kinetix:install` (or `npm install @tanstack/vue-virtual`);
it's fetched the first time a thread crosses the threshold.
:::

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

Hand the page a **signed reference** to the record, minted on the server, and
mount `KinetixComments` with it:

```php
use Happones\Kinetix\Comments\KinetixComments;

return inertia('Posts/Show', [
    'post'               => $post->only('id', 'title'),
    'comments_reference' => KinetixComments::reference($post),
]);
```

```vue
<script setup lang="ts">
import KinetixComments from "@/components/kinetix/KinetixComments.vue";
</script>

<template>
  <KinetixComments :reference="comments_reference" />
</template>
```

The reference names exactly that record. It is bound to the user and team it was
minted for and expires with `kinetix.tables.token_ttl`, so mint it on every page
render (never store it). The endpoints load the record from it, so the browser
can't point them at another one.

The legacy `commentable-type` + `:commentable-id` props still work while
`comments.require_reference` allows a raw type + id (see
[Authorization](#authorization)). Under teams they are refused by default.

It renders a composer, the threaded list (with author avatars/initials and
relative timestamps), inline **Reply**, and **Edit** / **Delete** on the user's
own comments. The server returns the full tree after each change, so the list
stays authoritative. `useKinetixComments(null, null, reference)` (the reference
as a ref, a getter or a string) exposes `{ comments, loading, load, post, edit,
remove }` for a custom UI; `useKinetixComments(type, id)` is the legacy form. All strings
are localized (`comment_*`, en/es/fr/pt).

---

## Authorization

- **Read / post**: any authenticated user who may view the commentable. When the
  model has a policy, Kinetix checks the `view` ability before allowing access.
- **Edit / delete**: restricted to the comment's author (HTTP 403 otherwise).
- **Allowlist**: a type that isn't registered returns 404, with a reference or
  without.
- **Signed reference**: a reference minted for another user or another team, an
  expired one, or a tags reference answers 403; an unreadable one 400.
- **Gating a type yourself** (an entitlement, a plan): the request body no
  longer names the type once it carries a reference, so read it with
  `KinetixComments::commentableType($request)`. It returns the allowlisted class
  the reference, the legacy `commentable_type` or the edited comment names, and
  `null` when the request names none or its reference isn't usable here:

  ```php
  // app/Http/Middleware/EnsureEntitledToComment.php (appended to `web`)
  public function handle(Request $request, Closure $next): Response
  {
      if ($request->routeIs('kinetix.comments.*')
          && KinetixComments::commentableType($request) === Product::class) {
          KinetixEntitlements::authorize('products.review');
      }

      return $next($request);
  }
  ```
- **Raw type + id** (`commentable_type` + `commentable_id`) names *any* record
  of an allowlisted class, so `comments.require_reference` decides when it is
  accepted:

  | `require_reference` | Raw type + id                                                                  |
  | ------------------- | ------------------------------------------------------------------------------ |
  | `null` (default)    | refused (403) when `kinetix.teams` is on; accepted otherwise                   |
  | `true`              | always refused                                                                 |
  | `false`             | accepted; under teams the model **must have a policy** (403 without one)       |

  Without teams a raw type + id behaves as before: the `view` policy is honoured
  when the model has one, and the record is allowed otherwise. Under teams a
  permission-only policy can't tell a record of your team from one of another
  team, which is why the reference is required there.

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on):

| Method   | Route                       | Name                     |
| -------- | --------------------------- | ------------------------ |
| `GET`    | `{prefix}/comments`         | `kinetix.comments.index` |
| `POST`   | `{prefix}/comments`         | `kinetix.comments.store` |
| `PUT`    | `{prefix}/comments/{id}`    | `kinetix.comments.update` |
| `DELETE` | `{prefix}/comments/{id}`    | `kinetix.comments.destroy` |

`index` / `store` take `reference` (or the legacy `commentable_type` +
`commentable_id`), and `body` / `parent_id` for `store`; `store` returns the
refreshed tree. Deleting a
top-level comment removes its replies too.
