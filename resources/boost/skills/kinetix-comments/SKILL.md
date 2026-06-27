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
  `index`/`store` resolve + authorize the commentable via the registry and an
  optional `view` policy; `update`/`destroy {comment}` are restricted to the
  author (403). Replies must target a top-level comment of the same commentable.
- `Comment` model: `morphTo commentable`, `hasMany replies`, `belongsTo author`.

---

## Frontend

```vue
<KinetixComments commentable-type="App\Models\Post" :commentable-id="post.id" />
```

Composer + threaded list (author avatar/initials, relative time, edited badge),
inline reply, and edit/delete on the user's own comments. `useKinetixComments(type, id)`
→ `{ comments, loading, load, post, edit, remove }`. i18n `comment_*` (en/es/fr/pt).
