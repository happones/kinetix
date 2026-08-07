---
name: kinetix-calendar
description: "Month/week/day-view event scheduler over an Eloquent model: events laid out on a grid with client-side navigation, timezone-correct rendering, and a built-in event-details modal/sheet. The Vue component is KinetixEventCalendar (distinct from the date-picker's KinetixCalendar). Activates when building a calendar/scheduler of events."
license: MIT
metadata:
  author: happones
---

# Kinetix Calendar Development

## When to Apply

Activate this skill when:
- Building a month/week/day calendar / scheduler of records that have dates.
- Rendering `<KinetixEventCalendar>`.
- The calendar needs to be correct across timezones, or needs an event
  details popup (modal or slide-in sheet).

**Component name:** use `KinetixEventCalendar` — `KinetixCalendar` is the
date-picker's single-date selector, a different component.

## Documentation

For full details, reference `docs/calendar.md` (published at https://happones.github.io/kinetix/calendar).

## Backend (server-driven, like Tables/Kanban)

```php
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Calendar\Calendar;

$calendar = Calendar::make(Event::query())
    ->dateColumn('starts_at')
    ->endColumn('ends_at')          // optional, inclusive multi-day/timed
    ->title('name')
    ->color(fn (Event $e) => $e->color)
    ->description(fn (Event $e) => $e->notes)   // optional, shown in the popup
    ->url(fn (Event $e) => route('events.show', $e))
    ->query(fn ($q) => $q->whereMonth('starts_at', now()->month))
    ->timezone(fn () => auth()->user()->timezone ?? 'UTC') // optional, defaults to config('app.timezone')
    ->eventActions([                                        // optional — omit for read-only
        Action::make('edit')->icon('pencil')
            ->inertiaVisit(fn (Event $e) => route('events.edit', $e)),
        Action::make('delete')->icon('trash')->color('danger')
            ->requiresConfirmation('Delete this event?')
            ->inertiaVisit(fn (Event $e) => route('events.destroy', $e), ['method' => 'delete']),
    ]);

return Inertia::render('Calendar', ['calendar' => $calendar->toData()]);
```

`eventActions()` resolves each `Action` against its event's underlying
record, exactly like `Table::recordActions()` — the same fluent `Action`
builder used by tables/page headers, so `inertiaVisit()`/`request()`
(background HTTP)/`dispatch()` (custom browser event)/`requiresConfirmation()`
/`authorize()`/`visible()`/`hidden()` all work identically here.

No migration or config flag — it's read-only by default and navigates
client-side over the supplied events (scope a sensible window for big
datasets).

### Drag-and-drop rescheduling (`moveable()`)

```php
Calendar::make(Event::query())
    ->dateColumn('starts_at')->endColumn('ends_at')->title('name')
    ->moveable()                        // opt-in: events become draggable
    ->authorizeMove('reschedule')       // optional; default ability `update` when a policy exists
    ->moveScope(['team_id' => $teamId]); // tenant guard, enforced on the endpoint lookup
```

Dragging an event to another day (month view) or hour slot (week/day) POSTs
`{model, recordId, start}` to `{prefix}/tables/calendar-move` — the signed
descriptor mirrors Kanban's (user-bound, expiring, columns sealed in). The end
column shifts by the same delta so durations survive. While dragging, the
source chip dims and the hovered cell/slot highlights with a dashed ghost chip
previewing the landing spot. Moves are optimistic
(snap back + error toast on failure, reload on success), work on touch via
long-press, and have a keyboard alternative (Alt + arrows: ±1 day, ±1 week in
month view, ±1 hour in time grids). The component emits `event-moved(event,
newStartIso)` after a successful move.

`start`/`end` always serialize as **absolute-instant ISO-8601 datetimes**
(never date-only) — this is what makes the frontend timezone-correct: an
event's real moment in time is preserved, so it can be re-rendered under any
timezone (the server's resolved default, or a client override) and still land
on the right local day/hour. `allDay` is auto-detected (start/end exactly at
midnight).

## Frontend

```vue
<KinetixEventCalendar
    :calendar="calendar"
    :week-starts-on="1"
    :views="['month', 'week', 'day']"
    event-display="sheet"
    sheet-side="right"
    @event-click="(e) => …"
    @day-click="(date) => …"
    @slot-click="(dateTime) => …"
/>
```

- **Views**: `views` opts into the month/week/day switcher (default
  `['month']`, unchanged single-view behavior). `view`/`v-model:view` for
  controlled state; `anchorDate` (ISO `Y-MM-DD`) sets the initial window —
  useful for deep-linking a specific date.
- **Timezone**: `timezone` prop overrides `calendar.timezone` (the server's
  default) — e.g. pass the viewer's own browser zone
  (`Intl.DateTimeFormat().resolvedOptions().timeZone`) for a true per-visitor
  calendar with zero backend round-trip.
- **Event details popup**: clicking an event opens a built-in modal (default)
  or sheet (`event-display="sheet"`, `sheet-side` top/right/bottom/left) with
  the color, formatted range, description, a "View details" link when `url`
  is set, and any `eventActions` as small icon+label buttons — identically in
  both the modal and the sheet. `@event-click` always fires too;
  `:show-event-details="false"` suppresses the built-in popup for fully
  custom handling.
- **Scroll-to-now**: switching into `week`/`day` (via the switcher, mounting
  directly in that view, or clicking "Today" while already there) auto-scrolls
  the hourly grid so the current time stays in view — a no-op when "now" falls
  outside `start-hour`/`end-hour`.
- `week-starts-on` 0–6 (default Monday); `locale` drives month/weekday/hour
  labels; `start-hour`/`end-hour` restrict the week/day hourly range (e.g.
  business hours `8`/`18`). i18n `calendar_*` (en/es/fr/pt/zh/ja/ru).

The new **`<KinetixSheet>`** primitive (`open`, `side`, `title`,
`description`, `#header`/`#footer` slots) is a standalone, reusable
shadcn-style slide-in panel — usable anywhere, not just for calendar events.

## Creating & editing events (CRUD wiring)

The calendar reads events; CRUD is regular page wiring. Preferred: an in-page
modal — a `KinetixPageHeader` action with `->dispatch('event-create')`, the
page listens for `kinetix:event-create` and opens a `KinetixModal` hosting
`<KinetixForm :form="eventForm" flat @submit="submit" />` (**always pass
`flat` in modals** — the panel is the surface; Sections must not nest a card
inside it). `@day-click`/`@slot-click` prefill the start for click-to-create.
Controllers persist and flash `back()->with('kinetix_toast',
__('kinetix.record_created'))` (`record_updated`/`record_deleted` for the
other verbs). Alternative: dedicated pages via `eventActions()` +
`inertiaVisit()` and `redirect()->with('kinetix_toast', …)`. Full example:
docs/calendar.md §7.
