# Calendar

Kinetix Calendar renders a **month/week/day-view scheduler** of events from any
Eloquent model. It's a server-driven builder like [Tables](/tables) /
[Kanban](/kanban): you declare the date column and how to title/color each
event, and the `<KinetixEventCalendar>` component lays them out and navigates
client-side — no calendar dependency.

<Screenshot name="event-calendar" alt="Month-view event calendar" />

> Not to be confused with the date-picker's calendar — this is the **event
> scheduler** component, `KinetixEventCalendar`.

---

## 1. Defining a calendar

```php
use Happones\Kinetix\Calendar\Calendar;

$calendar = Calendar::make(Event::query())
    ->dateColumn('starts_at')
    ->endColumn('ends_at')                       // optional, for multi-day/allday spans
    ->title('name')
    ->color(fn (Event $e) => $e->calendar->color)
    ->description(fn (Event $e) => $e->notes)     // optional, shown in the details popup
    ->url(fn (Event $e) => route('events.show', $e))
    ->query(fn ($q) => $q->where('team_id', auth()->user()->currentTeam->id))
    ->timezone('America/Mexico_City')             // optional, defaults to config('app.timezone')
    ->heading('Schedule');

return Inertia::render('Calendar', ['calendar' => $calendar->toData()]);
```

- **`dateColumn`** — the event's date/datetime column (default `date`).
- **`endColumn`** — optional inclusive end date/datetime for multi-day or
  timed events with a duration.
- **`title`** / **`color`** / **`description`** / **`url`** — an attribute
  name or a closure.
- **`query`** — scope the events (the component navigates client-side over
  whatever you supply, so scope to a sensible window for large datasets).
- **`timezone`** — a string or `fn () => ...` closure (e.g.
  `fn () => auth()->user()->timezone`). Defaults to `config('app.timezone')`.
  See [§3 Timezones](#3-timezones) for why this rarely needs to be touched.

---

## 2. Rendering

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

### Props

| Prop                | Type                                  | Default   | Notes |
| ------------------- | -------------------------------------- | --------- | ----- |
| `calendar`           | `KinetixCalendarData`                 | —         | From `Calendar::toData()` |
| `weekStartsOn`       | `number`                              | `1`       | 0=Sunday … 6=Saturday |
| `locale`             | `string \| null`                       | `null`    | BCP-47 locale for month/weekday/hour labels |
| `timezone`           | `string \| null`                       | `null`    | Overrides `calendar.timezone` (e.g. the viewer's own browser zone) |
| `views`              | `('month'\|'week'\|'day')[]`          | `['month']` | Opts into the view switcher — see [§4](#4-month-week-day-views) |
| `view`               | `'month'\|'week'\|'day'`              | `views[0]`| Controlled active view (`v-model:view`) |
| `anchorDate`         | `string \| null`                       | `null`    | Initial month/week/day (ISO `Y-MM-DD`), e.g. for deep-linking. Defaults to today |
| `startHour`/`endHour`| `number`                              | `0`/`24`  | Visible hour range in week/day views |
| `eventDisplay`       | `'modal'\|'sheet'`                    | `'modal'` | How a clicked event's details are shown — see [§5](#5-event-details-modal--sheet) |
| `sheetSide`          | `'top'\|'right'\|'bottom'\|'left'`    | `'right'` | Which edge the sheet slides from (`eventDisplay="sheet"`) |
| `showEventDetails`   | `boolean`                             | `true`    | Set `false` to suppress the built-in popup and rely on `@event-click` |

### Events & slots

- Events render as colored chips (month view) or time-positioned blocks
  (week/day views). Beyond three per day in month view, a "+N more" hint is
  shown.
- Emits **`event-click`** (the event — always fires, regardless of
  `showEventDetails`), **`day-click`** (the ISO date, month view empty-cell
  clicks), **`slot-click`** (the ISO datetime, week/day view empty-slot
  clicks), and **`update:view`**.

No endpoint, migration or config flag is needed — the calendar is read-only
and navigates client-side.

---

## 3. Timezones

Events serialize as **absolute-instant ISO-8601 datetimes** (never date-only
strings), so the frontend can always re-render them correctly regardless of
the viewing browser's own local timezone. `Calendar::toData()` resolves a
`timezone` once server-side — `config('app.timezone')` by default — and sends
it down as `calendar.timezone`.

You rarely need to touch this: because every event is an absolute instant, the
frontend can correctly re-render it in **any** timezone, whether that's the
server's resolved default or a client-side override:

```php
// Per-user timezone, resolved server-side:
Calendar::make(Event::query())->timezone(fn () => auth()->user()->timezone ?? 'UTC');
```

```vue
<!-- Or override client-side — e.g. the viewer's own browser zone: -->
<KinetixEventCalendar
    :calendar="calendar"
    :timezone="Intl.DateTimeFormat().resolvedOptions().timeZone"
/>
```

Both approaches are "correct" — an event at `2026-06-15T09:00:00+00:00` lands
on the same real-world moment no matter which timezone string the calendar
renders it in; `timezone` only changes which **local day/hour** that moment is
displayed under.

---

## 4. Month / week / day views

Multiple views are **opt-in** — pass `views` with more than one entry to show
a switcher; the default (`views: ['month']`) is unchanged from a plain
month-only calendar:

```vue
<KinetixEventCalendar :calendar="calendar" :views="['month', 'week', 'day']" />
```

<Screenshot name="event-calendar-week" alt="Event calendar — week view" />

<Screenshot name="event-calendar-day" alt="Event calendar — day view" />

- **`month`** — the classic 6-week grid; events spanning multiple days show
  on every day they cover.
- **`week`** — 7 day-columns with an hourly grid; an **all-day banner** above
  it for `allDay`/multi-day events, and a **current-time indicator** line on
  today's column.
- **`day`** — the same hourly grid for a single day.

`startHour`/`endHour` restrict the visible hour range (e.g. `:start-hour="8"
:end-hour="18"` for business hours). The hourly grid sits in its own
horizontally-scrollable container, so 7 day-columns never break the page's
layout on narrow viewports — verified at mobile/tablet/desktop widths.

An event's `allDay` flag (from `CalendarEventData`) is auto-detected
server-side: true when its start (and end, if set) fall exactly at midnight.
Timed events with a genuine hour/minute component render as positioned blocks
in the hourly grid instead.

`anchorDate` sets which month/week/day is shown initially (defaults to
today) — handy for deep-linking a specific date from the URL.

---

## 5. Event details: modal & sheet

Clicking an event opens a built-in details popup — the color swatch, title,
formatted date/time range (in the effective timezone), description, and a
"View details" link when `url` is set:

<Screenshot name="event-calendar-details" alt="Event calendar — event details popup" />

```vue
<!-- Default: a centered modal. -->
<KinetixEventCalendar :calendar="calendar" />

<!-- Or a shadcn-style slide-in sheet, from any edge: -->
<KinetixEventCalendar :calendar="calendar" event-display="sheet" sheet-side="right" />
```

`@event-click` always fires too, so you can layer custom behavior (analytics,
routing) regardless of `eventDisplay`. Pass `:show-event-details="false"` to
suppress the built-in popup entirely and handle everything yourself via
`@event-click`.

The sheet is powered by a standalone **`<KinetixSheet>`** primitive
(`open`, `side`, `title`, `description` props; `#header`/`#footer` slots) —
reusable anywhere you want a shadcn Sheet-style slide-in panel, not just here.

---

## 6. Localization

`week-starts-on`, `locale` (BCP-47, via `Intl.DateTimeFormat`), and all UI
strings (`calendar_*`) are localized (en/es/fr/pt/zh/ja/ru).
