import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Timezone-aware "current moment" helpers for the date/time pickers.
 *
 * The pickers deal in NAIVE wall-time strings ('Y-m-d', 'H:i', 'Y-m-dTH:i')
 * whose implicit timezone is Laravel's `app.timezone` — that is what
 * `Carbon::parse()` assumes on the server. Anything computing "now" or
 * "today" client-side must therefore ask the clock in that timezone, never
 * the browser's: a viewer in Madrid using a Mexico City app would otherwise
 * get Today/Now presets shifted by hours (or a whole day around midnight).
 */

export interface ZonedNowParts {
    year: number;
    month: number; // 1-12
    day: number;
    hour: number; // 0-23
    minute: number;
}

const pad = (n: number): string => String(n).padStart(2, '0');

/**
 * Wall-clock "now" in an IANA timezone. Null/undefined/invalid timezones
 * fall back to the browser clock (never throws).
 */
export function zonedNow(timeZone?: string | null): ZonedNowParts {
    const now = new Date();

    if (timeZone) {
        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }).formatToParts(now);

            const get = (type: string): number =>
                Number(parts.find((p) => p.type === type)?.value ?? '0');

            return {
                year: get('year'),
                month: get('month'),
                day: get('day'),
                // Some engines render midnight as '24' with hour12: false.
                hour: get('hour') % 24,
                minute: get('minute'),
            };
        } catch {
            // Invalid IANA name — browser clock below.
        }
    }

    return {
        year: now.getFullYear(),
        month: now.getMonth() + 1,
        day: now.getDate(),
        hour: now.getHours(),
        minute: now.getMinutes(),
    };
}

/** Today as an ISO 'Y-m-d' string in the given timezone. */
export function zonedTodayIso(timeZone?: string | null): string {
    const { year, month, day } = zonedNow(timeZone);

    return `${year}-${pad(month)}-${pad(day)}`;
}

/**
 * The timezone a picker should compute "now" in: its explicit `timezone`
 * prop → the app timezone Kinetix shares (`kinetix_config.timezone`, i.e.
 * Laravel's `config('app.timezone')`) → null (browser clock).
 */
export function useKinetixTimezone(
    explicit?: () => string | null | undefined,
): ComputedRef<string | null> {
    const page = usePage<KinetixSharedProps>();

    return computed(
        () => explicit?.() ?? page?.props?.kinetix_config?.timezone ?? null,
    );
}
