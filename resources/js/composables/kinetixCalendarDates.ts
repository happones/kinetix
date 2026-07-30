import { CalendarDate, parseAbsolute } from '@internationalized/date';
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
}

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

        return {
            event,
            start,
            end,
            startKey,
            endKey: end ? dateKeyOf(end) : startKey,
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
