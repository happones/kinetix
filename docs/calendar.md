# Calendar

Kinetix Calendar renders a **month-view scheduler** of events from any Eloquent
model. It's a server-driven builder like [Tables](/tables) / [Kanban](/kanban):
you declare the date column and how to title/color each event, and the
`<KinetixEventCalendar>` component lays them out on a month grid and navigates
months client-side — no calendar dependency.

<Screenshot name="event-calendar" alt="Month-view event calendar" />

> Not to be confused with the date-picker's calendar — this is the **event
> scheduler** component, `KinetixEventCalendar`.

---

## Defining a calendar

```php
use Happones\Kinetix\Calendar\Calendar;

$calendar = Calendar::make(Event::query())
    ->dateColumn('starts_at')
    ->endColumn('ends_at')                       // optional, for multi-day spans
    ->title('name')
    ->color(fn (Event $e) => $e->calendar->color)
    ->url(fn (Event $e) => route('events.show', $e))
    ->query(fn ($q) => $q->where('team_id', auth()->user()->currentTeam->id))
    ->heading('Schedule');

return Inertia::render('Calendar', ['calendar' => $calendar->toData()]);
```

- **`dateColumn`** — the event's date/datetime column (default `date`).
- **`endColumn`** — optional inclusive end date for multi-day events.
- **`title`** / **`color`** / **`url`** — an attribute name or a closure.
- **`query`** — scope the events (the component navigates months over whatever
  you supply, so scope to a sensible window for large datasets).

---

## Rendering

```vue
<script setup lang="ts">
import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

defineProps<{ calendar: object }>();
</script>

<template>
    <KinetixEventCalendar
        :calendar="calendar"
        :week-starts-on="1"
        @event-click="(e) => …"
        @day-click="(date) => …"
    />
</template>
```

- **`week-starts-on`** — 0=Sunday … 6=Saturday (default Monday).
- **`locale`** — BCP-47 locale for the month + weekday labels (via
  `Intl.DateTimeFormat`).
- Events render as colored chips; those with a `url` are links. Beyond three per
  day a "+N more" hint is shown.
- Emits **`event-click`** (the event) and **`day-click`** (the ISO date).

No endpoint, migration or config flag is needed — the calendar is read-only and
navigates client-side.
