---
name: kinetix-calendar
description: "Month-view event scheduler over an Eloquent model: events laid out on a month grid with client-side navigation. The Vue component is KinetixEventCalendar (distinct from the date-picker's KinetixCalendar). Activates when building a calendar/scheduler of events."
license: MIT
metadata:
  author: happones
---

# Kinetix Calendar Development

## When to Apply

Activate this skill when:
- Building a month-view calendar / scheduler of records that have dates.
- Rendering `<KinetixEventCalendar>`.

**Component name:** use `KinetixEventCalendar` — `KinetixCalendar` is the
date-picker's single-date selector, a different component.

## Documentation

For full details, reference `docs/calendar.md` (published at https://happones.github.io/kinetix/calendar).

## Backend (server-driven, like Tables/Kanban)

```php
use Happones\Kinetix\Calendar\Calendar;

$calendar = Calendar::make(Event::query())
    ->dateColumn('starts_at')
    ->endColumn('ends_at')          // optional, inclusive multi-day
    ->title('name')
    ->color(fn (Event $e) => $e->color)
    ->url(fn (Event $e) => route('events.show', $e))
    ->query(fn ($q) => $q->whereMonth('starts_at', now()->month));

return Inertia::render('Calendar', ['calendar' => $calendar->toData()]);
```

No migration, route or config flag — it's read-only and navigates months
client-side over the supplied events (scope a sensible window for big datasets).

## Frontend

```vue
<KinetixEventCalendar
    :calendar="calendar"
    :week-starts-on="1"
    @event-click="(e) => …"
    @day-click="(date) => …"
/>
```

Colored event chips (links when an event has a `url`), `+N more` beyond 3/day,
today highlighted. `week-starts-on` 0–6 (default Monday); `locale` drives the
month/weekday labels. i18n `calendar_*` (en/es/fr/pt).
