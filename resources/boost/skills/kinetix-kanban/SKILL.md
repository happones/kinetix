---
name: kinetix-kanban
description: "Drag-and-drop board over an Eloquent model: records grouped into columns by a status attribute; dragging a card persists the new status. Activates when building a Kanban/status board."
license: MIT
metadata:
  author: happones
---

# Kinetix Kanban Development

## When to Apply

Activate this skill when:
- Building a status board / Kanban over a model (tasks, leads, tickets…).
- Rendering `<KinetixKanban>` or letting users drag cards between statuses.

## Documentation

For full details, reference `docs/kanban.md` (published at https://happones.github.io/kinetix/kanban).

## Backend (server-driven, like Tables)

```php
use Happones\Kinetix\Kanban\Kanban;

$board = Kanban::make(Task::query())
    ->statusColumn('status')
    ->statuses([
        'todo'  => 'To Do',
        'doing' => ['label' => 'In Progress', 'color' => '#3b82f6'],
        'done'  => ['label' => 'Done', 'color' => '#22c55e'],
    ])
    ->cardTitle('title')
    ->cardDescription(fn (Task $t) => $t->assignee?->name)
    ->query(fn ($q) => $q->where('archived', false));

return Inertia::render('Tasks/Board', ['board' => $board->toData()]);
```

No migration or config flag. `toData()` bakes a signed descriptor; the
`POST {prefix}/tables/kanban-move` endpoint (always registered) decrypts it and
only writes the declared status column to one of the declared statuses.

## Frontend

```vue
<KinetixKanban :kanban="board" />
```

Native HTML5 drag-and-drop (no extra dependency). Moves are optimistic (revert +
toast on failure) and trigger a `router.reload()` on success. i18n `kanban_*`
(en/es/fr/pt).
