# Kanban

Kinetix Kanban turns any Eloquent model into a **drag-and-drop board**: records
are grouped into columns by a status attribute, and dragging a card to another
column persists the new status. It's a server-driven builder like
[Tables](/tables) — the move is guarded by a signed descriptor, so only the
status column and the declared statuses can be written.

<Screenshot name="kanban" alt="Kanban board" />

---

## Defining a board

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
    ->query(fn ($q) => $q->where('archived', false))   // optional base scope
    ->heading('Tasks');

return Inertia::render('Tasks/Board', ['board' => $board->toData()]);
```

- **`statusColumn`** — the DB column holding the status (default `status`).
- **`statuses`** — column key → label, or `['label' => …, 'color' => …]`.
- **`cardTitle`** / **`cardDescription`** — an attribute name or a closure.
- **`query`** — modify the base query (filters, eager loads).

---

## Rendering

```vue
<script setup lang="ts">
import KinetixKanban from '@/components/KinetixKanban.vue';

defineProps<{ board: object }>();
</script>

<template>
    <KinetixKanban :kanban="board" />
</template>
```

Cards are draggable (native HTML5 drag-and-drop — no extra dependency). Dropping
a card into another column moves it optimistically and `POST`s the change; if the
request fails the card snaps back and a toast is shown. The board reloads after a
successful move so server-side ordering/derived data stays in sync.

---

## How the move is secured

`toData()` bakes a **signed descriptor** (`Crypt::encrypt`) of the model, status
column and allowed statuses into the payload — the same mechanism as editable
table cells. The `kanban-move` endpoint decrypts it and only writes the declared
status column to one of the declared statuses, so a client can't tamper with the
target column or push an arbitrary value.

| Method | Route                              | Name                          |
| ------ | ---------------------------------- | ----------------------------- |
| `POST` | `{prefix}/tables/kanban-move`      | `kinetix.tables.kanban-move`  |

The endpoint takes `{ model, recordId, status }` and is always available (guarded
by the descriptor); no migration or config flag is needed.
