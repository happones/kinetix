import { afterEach, describe, expect, it, vi } from 'vitest';
import { zonedNow, zonedTodayIso } from '@/composables/useKinetixTimezone';

/**
 * Fixed instant: 2026-08-05 20:00 UTC.
 * Pacific/Kiritimati is UTC+14 (no DST) → 2026-08-06 10:00 there — both the
 * date AND the hour differ from UTC, which is exactly what the pickers'
 * Today/Now presets must respect.
 */
const INSTANT = new Date(Date.UTC(2026, 7, 5, 20, 0));

describe('zonedNow / zonedTodayIso', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('reads the wall clock in the given IANA timezone', () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(INSTANT);

        const utc = zonedNow('UTC');
        expect(utc).toEqual({
            year: 2026,
            month: 8,
            day: 5,
            hour: 20,
            minute: 0,
        });

        const kiritimati = zonedNow('Pacific/Kiritimati');
        expect(kiritimati).toEqual({
            year: 2026,
            month: 8,
            day: 6,
            hour: 10,
            minute: 0,
        });
    });

    it("today's ISO date follows the timezone, not the browser", () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(INSTANT);

        expect(zonedTodayIso('UTC')).toBe('2026-08-05');
        expect(zonedTodayIso('Pacific/Kiritimati')).toBe('2026-08-06');
    });

    it('falls back to the browser clock for null or invalid timezones', () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(2026, 7, 5, 14, 33));

        for (const tz of [null, undefined, 'Not/AZone']) {
            const parts = zonedNow(tz as string | null);
            expect(parts).toEqual({
                year: 2026,
                month: 8,
                day: 5,
                hour: 14,
                minute: 33,
            });
        }
    });
});
