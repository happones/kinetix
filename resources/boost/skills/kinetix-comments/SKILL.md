---
name: kinetix-comments
description: "Polymorphic, threaded comments on any model: read/post for viewers, one-level replies, author-only edit/delete, allowlisted commentable types. Activates when adding comments/discussions to a model."
license: MIT
metadata:
  author: happones
---

# Kinetix Comments Development

## When to Apply

Activate this skill when:
- Adding comments / discussion threads to any model (post, task, invoice, …).
- Mounting `<KinetixComments>` or using `useKinetixComments`.
- Declaring which models accept comments.

## Documentation

For full details, reference `docs/comments.md` (published at https://happones.github.io/kinetix/comments).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-comments-migrations
php artisan migrate
```

```php
'comments' => ['enabled' => env('KINETIX_COMMENTS_ENABLED', false)],
```

```php
use Happones\Kinetix\Comments\KinetixComments;

// AppServiceProvider::boot() — allowlist commentable models (class or morph alias)
KinetixComments::for([\App\Models\Post::class, \App\Models\Task::class]);
```

Only allowlisted models can be commented on (unregistered types 404).

---

## Backend

- **`CommentManager`**: `for($commentable, $user)` returns a 1-level threaded
  tree of `CommentData` (`editable` = author is the current user); `create`,
  `update`, `delete` (delete cascades replies).
- **`CommentController`** (self-service, team-aware `{prefix}/comments`):
  `index`/`store` take `reference` = `KinetixComments::reference($model)`
  (signed, audience `comments`, bound to user + team + expiry; 403 when
  foreign/expired, 400 unreadable), then the registry allowlist and an optional
  `view` policy. Legacy `commentable_type` + `commentable_id`:
  `comments.require_reference` null = refused under teams (403), true = always
  refused, false = accepted but under teams the model MUST have a policy. Teams
  off = unchanged (policy if any, else allowed); `update`/`destroy {comment}` are restricted to the
  author (403). Replies must target a top-level comment of the same commentable.
- **Gating comments per type in host middleware** (entitlement, plan): NEVER read
  `$request->input('commentable_type')` (a referenced request has none, so the
  gate silently passes). Use `KinetixComments::commentableType($request)`: the
  allowlisted class from the reference, legacy type or edited `{comment}`; null
  when none or the reference isn't usable.
- `Comment` model: `morphTo commentable`, `hasMany replies`, `belongsTo author`.

---

## Frontend

```vue
<!-- controller: 'comments_reference' => KinetixComments::reference($post) (mint per render) -->
<KinetixComments :reference="comments_reference" />
<!-- legacy (refused under teams by default): commentable-type + :commentable-id -->
```

Composer + threaded list (author avatar/initials, relative time, edited badge),
inline reply, and edit/delete on the user's own comments.
`useKinetixComments(null, null, reference)` (ref, getter or string; legacy
`useKinetixComments(type, id)`) → `{ comments, loading, load, post, edit, remove }`. i18n `comment_*` (en/es/fr/pt).
Threads over ~40 comments virtualize through `useKinetixVirtualRows`, which
loads `@tanstack/vue-virtual` only when the threshold is crossed.

## UUID / ULID Host Models

This feature's migration builds `user_id`, plus the `commentable_id` morph id with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time from its primary key (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
