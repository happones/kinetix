import {
    CalendarDate,
    CalendarDateTime,
    parseAbsolute,
    toZoned,
} from '@internationalized/date';
import type { ZonedDateTime } from '@internationalized/date';
import type { KinetixCalendarEvent } from '@/types/kinetix';

/** Zero-pad a month/day number to two digits. */
export function pad(n: number): string {
    return String(n).padStart(2, '0');
}

/** The `Y-MM-DD` key used to place and compare events by calendar day. */
export function dateKeyOf(d: {
    year: number;
    month: number;
    day: number;
}): string {
    return `${d.year}-${pad(d.month)}-${pad(d.day)}`;
}

/** Parse an ISO `Y-MM-DD` prefix into a plain `CalendarDate` (or null). */
export function parseAnchorDate(value: string): CalendarDate | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    return match
        ? new CalendarDate(Number(match[1]), Number(match[2]), Number(match[3]))
        : null;
}

/**
 * Sunday-based (0-6) day-of-week, computed from the plain calendar date —
 * independent of any timezone (a CalendarDate has no time component).
 */
export function dayOfWeekOf(d: {
    year: number;
    month: number;
    day: number;
}): number {
    return new Date(Date.UTC(d.year, d.month - 1, d.day)).getUTCDay();
}

/** An event with its absolute instants resolved into the effective timezone. */
export interface PreparedEvent {
    event: KinetixCalendarEvent;
    start: ZonedDateTime;
    end: ZonedDateTime | null;
    startKey: string;
    endKey: string;
    /**
     * How far into its `endKey` day the event runs, in minutes — 1440 for a
     * timed event ending exactly at the next midnight, an hour past the start
     * for one without an end.
     */
    endMinutes: number;
}

const minutesOf = (d: ZonedDateTime): number => d.hour * 60 + d.minute;

/**
 * Resolve every event's absolute ISO instant into `tz` ONCE, caching each
 * event's start/end day keys. The month and week/day grids then place events by
 * cheap string-key comparison instead of re-running `parseAbsolute` for every
 * event in every calendar cell — the calendar's main hot path.
 */
export function prepareEvents(
    events: KinetixCalendarEvent[],
    tz: string,
): PreparedEvent[] {
    return events.map((event) => {
        const start = parseAbsolute(event.start, tz);
        const end = event.end ? parseAbsolute(event.end, tz) : null;
        const startKey = dateKeyOf(start);

        if (!end) {
            return {
                event,
                start,
                end,
                startKey,
                endKey: startKey,
                endMinutes: minutesOf(start) + 60,
            };
        }

        // A timed event ending exactly at midnight closes the day before it;
        // an all-day event's end day is inclusive.
        const closesDayBefore =
            !event.allDay &&
            minutesOf(end) === 0 &&
            end.second === 0 &&
            end.millisecond === 0 &&
            end.compare(start) > 0;

        return {
            event,
            start,
            end,
            startKey,
            endKey: dateKeyOf(
                closesDayBefore ? end.subtract({ days: 1 }) : end,
            ),
            endMinutes: closesDayBefore ? 24 * 60 : minutesOf(end),
        };
    });
}

/** Prepared events whose span covers the given day key (all-day/multi-day aware). */
export function eventsCoveringDay(
    prepared: PreparedEvent[],
    key: string,
): PreparedEvent[] {
    return prepared.filter((p) => key >= p.startKey && key <= p.endKey);
}

/** The minutes a resized end snaps to. */
export const RESIZE_STEP_MINUTES = 15;

/**
 * Where an event ends, for resizing: its end, or — without one — its own day
 * for an all-day event and an hour after the start for a timed one (the block
 * the hour grid draws).
 */
export function effectiveEnd(
    event: KinetixCalendarEvent,
    tz: string,
): ZonedDateTime {
    const start = parseAbsolute(event.start, tz);

    if (event.end) {
        return parseAbsolute(event.end, tz);
    }

    return event.allDay ? start : start.add({ hours: 1 });
}

/**
 * A resized end as an ISO instant, kept after the start: a timed event lasts
 * at least one resize step, an all-day event at least its own day. Null when
 * it lands where the event already ends (nothing to save).
 */
const isMidnight = (instant: ZonedDateTime): boolean =>
    instant.hour === 0 &&
    instant.minute === 0 &&
    instant.second === 0 &&
    instant.millisecond === 0;

export function resizedEnd(
    event: KinetixCalendarEvent,
    candidate: ZonedDateTime,
    tz: string,
): string | null {
    const start = parseAbsolute(event.start, tz);
    const floor = event.allDay
        ? start
        : start.add({ minutes: RESIZE_STEP_MINUTES });
    let end = candidate.compare(floor) < 0 ? floor : candidate;

    // A timed event running from a midnight to a midnight reads back as an
    // all-day one: it stops a step short instead.
    if (!event.allDay && isMidnight(start) && isMidnight(end)) {
        end = end.subtract({ minutes: RESIZE_STEP_MINUTES });
    }

    return end.compare(effectiveEnd(event, tz)) === 0
        ? null
        : end.toDate().toISOString();
}

/**
 * The instant at a point down a day's hour grid, snapped to the resize step:
 * `fraction` 0 is the top of `startHour`, 1 the bottom of the last hour.
 */
export function gridInstant(
    day: CalendarDate,
    fraction: number,
    startHour: number,
    endHour: number,
    tz: string,
): ZonedDateTime {
    const span = (endHour - startHour) * 60;
    const offset = Math.min(Math.max(fraction, 0), 1) * span;
    const minutes =
        startHour * 60 +
        Math.round(offset / RESIZE_STEP_MINUTES) * RESIZE_STEP_MINUTES;

    return toZoned(
        new CalendarDateTime(day.year, day.month, day.day).add({ minutes }),
        tz,
    );
}

/** An event instant as the calendar announces it: the date, plus the time unless all-day. */
export function formatEventInstant(
    iso: string,
    allDay: boolean,
    locale: string | undefined,
    tz: string,
): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        ...(allDay ? {} : { timeStyle: 'short' as const }),
        timeZone: tz,
    }).format(new Date(iso));
}
