import { describe, expect, it } from 'vitest';
import {
    dateKeyOf,
    dayOfWeekOf,
    eventsCoveringDay,
    pad,
    parseAnchorDate,
    prepareEvents,
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
});
