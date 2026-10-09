import { CalendarDate, parseAbsolute } from '@internationalized/date';
import { describe, expect, it } from 'vitest';
import {
    dateKeyOf,
    dayOfWeekOf,
    effectiveEnd,
    eventsCoveringDay,
    gridInstant,
    pad,
    parseAnchorDate,
    prepareEvents,
    resizedEnd,
} from '@/composables/kinetixCalendarDates';

const event = (id: number, start: string, end: string | null = null) =>
    ({ id, title: `E${id}`, start, end, allDay: false }) as any;

describe('kinetixCalendarDates helpers', () => {
    it('pads and builds day keys', () => {
        expect(pad(3)).toBe('03');
        expect(dateKeyOf({ year: 2026, month: 1, day: 7 })).toBe('2026-01-07');
    });

    it('parses an ISO anchor prefix, or null', () => {
        expect(parseAnchorDate('2026-01-07T09:00')?.day).toBe(7);
        expect(parseAnchorDate('nonsense')).toBeNull();
    });

    it('computes a timezone-independent day of week', () => {
        // 2024-01-07 is a Sunday (0).
        expect(dayOfWeekOf({ year: 2024, month: 1, day: 7 })).toBe(0);
    });

    it('prepares events into a timezone once, caching day keys', () => {
        const prepared = prepareEvents(
            [event(1, '2026-01-07T23:30:00Z')],
            'America/Mexico_City', // UTC-6: 23:30Z -> 17:30 same day
        );

        expect(prepared[0].startKey).toBe('2026-01-07');
        expect(prepared[0].start.hour).toBe(17);
    });

    it('finds events covering a day, including multi-day spans', () => {
        const prepared = prepareEvents(
            [
                event(1, '2026-01-07T10:00:00Z'),
                event(2, '2026-01-06T10:00:00Z', '2026-01-09T10:00:00Z'),
            ],
            'UTC',
        );

        const onThe8th = eventsCoveringDay(prepared, '2026-01-08').map(
            (p) => p.event.id,
        );
        // Only the multi-day event (2) spans the 8th.
        expect(onThe8th).toEqual([2]);

        const onThe7th = eventsCoveringDay(prepared, '2026-01-07').map(
            (p) => p.event.id,
        );
        expect(onThe7th.sort()).toEqual([1, 2]);
    });

    it('a timed event ending exactly at midnight closes the day before', () => {
        const [late] = prepareEvents(
            [event(1, '2026-01-07T22:00:00Z', '2026-01-08T00:00:00Z')],
            'UTC',
        );

        expect(late.endKey).toBe('2026-01-07');
        expect(late.endMinutes).toBe(24 * 60);
        expect(eventsCoveringDay([late], '2026-01-08')).toEqual([]);

        // An all-day event's end day is inclusive.
        const [allDay] = prepareEvents(
            [
                {
                    ...event(2, '2026-01-07T00:00:00Z', '2026-01-08T00:00:00Z'),
                    allDay: true,
                },
            ],
            'UTC',
        );
        expect(allDay.endKey).toBe('2026-01-08');
    });

    it('an event without an end runs an hour (timed) or its own day (all-day)', () => {
        expect(
            effectiveEnd(event(1, '2026-01-07T09:30:00Z'), 'UTC').toString(),
        ).toContain('2026-01-07T10:30');
        expect(
            effectiveEnd(
                { ...event(2, '2026-01-07T00:00:00Z'), allDay: true },
                'UTC',
            ).toString(),
        ).toContain('2026-01-07T00:00');
        expect(
            prepareEvents([event(3, '2026-01-07T09:30:00Z')], 'UTC')[0],
        ).toMatchObject({ endKey: '2026-01-07', endMinutes: 10 * 60 + 30 });
    });

    it('a resized end stays after the start and is null when unchanged', () => {
        const timed = event(1, '2026-01-07T09:00:00Z', '2026-01-07T10:00:00Z');
        const at = (iso: string) => parseAbsolute(iso, 'UTC');

        expect(resizedEnd(timed, at('2026-01-07T11:15:00Z'), 'UTC')).toBe(
            '2026-01-07T11:15:00.000Z',
        );
        // Before the start: held at one resize step.
        expect(resizedEnd(timed, at('2026-01-07T08:00:00Z'), 'UTC')).toBe(
            '2026-01-07T09:15:00.000Z',
        );
        expect(resizedEnd(timed, at('2026-01-07T10:00:00Z'), 'UTC')).toBeNull();

        // An all-day event may end on its own (inclusive) day.
        const allDay = {
            ...event(2, '2026-01-07T00:00:00Z', '2026-01-09T00:00:00Z'),
            allDay: true,
        };
        expect(resizedEnd(allDay, at('2026-01-05T00:00:00Z'), 'UTC')).toBe(
            '2026-01-07T00:00:00.000Z',
        );
    });

    it('a point down the hour grid snaps to 15 minutes, up to the next midnight', () => {
        const day = new CalendarDate(2026, 1, 7);
        const iso = (fraction: number) =>
            gridInstant(day, fraction, 8, 24, 'America/Mexico_City')
                .toDate()
                .toISOString();

        // 8:00 + 0.25 × 16h = 12:00 local (UTC-6).
        expect(iso(0.25)).toBe('2026-01-07T18:00:00.000Z');
        // 12:07 rounds to 12:00, 12:08 to 12:15.
        expect(iso(0.25 + 7 / 960)).toBe('2026-01-07T18:00:00.000Z');
        expect(iso(0.25 + 8 / 960)).toBe('2026-01-07T18:15:00.000Z');
        // The bottom of a 24:00 grid is the next midnight; past it, clamped.
        expect(iso(1)).toBe('2026-01-08T06:00:00.000Z');
        expect(iso(1.4)).toBe('2026-01-08T06:00:00.000Z');
        expect(iso(-1)).toBe('2026-01-07T14:00:00.000Z');
    });
});
