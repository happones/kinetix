/**
 * The IANA zone list behind `<KinetixTimezonePicker>`, shared by every picker
 * on the page. Building it costs one `Intl.DateTimeFormat` per zone (~420), so
 * it is built lazily and cached for the current hour: offsets only move at a
 * DST switch, which lands on an hour boundary.
 */
export interface KinetixZoneEntry {
    value: string;
    region: string;
    name: string;
    offset: string;
    offsetMinutes: number;
}

const HOUR_MS = 3_600_000;

let cache: {
    bucket: number;
    zones: readonly KinetixZoneEntry[];
    byValue: Map<string, KinetixZoneEntry>;
} | null = null;

/** The hour the cache belongs to. */
export function kinetixTimezoneBucket(): number {
    return Math.floor(Date.now() / HOUR_MS);
}

/** `UTC±hh:mm` and minutes east of UTC for a zone, or null for an unknown zone. */
export function kinetixTimezoneOffsetFor(
    tz: string,
): { label: string; minutes: number } | null {
    const cached =
        cache?.bucket === kinetixTimezoneBucket()
            ? cache.byValue.get(tz)
            : undefined;

    if (cached) {
        return { label: cached.offset, minutes: cached.offsetMinutes };
    }

    let parts: Intl.DateTimeFormatPart[];

    try {
        parts = new Intl.DateTimeFormat('en-US', {
            timeZone: tz,
            timeZoneName: 'longOffset',
        }).formatToParts(new Date());
    } catch {
        return null;
    }

    const raw =
        parts.find((p) => p.type === 'timeZoneName')?.value ?? 'GMT+00:00';
    const label = raw.replace('GMT', 'UTC');
    const match = /UTC([+-])(\d{2}):(\d{2})/.exec(label);
    const minutes = match
        ? (match[1] === '-' ? -1 : 1) *
          (Number(match[2]) * 60 + Number(match[3]))
        : 0;

    return { label: label === 'UTC' ? 'UTC+00:00' : label, minutes };
}

/** Every supported zone, sorted by offset then name; rebuilt once the hour rolls over. */
export function kinetixTimezoneZones(): readonly KinetixZoneEntry[] {
    const bucket = kinetixTimezoneBucket();

    if (cache?.bucket === bucket) {
        return cache.zones;
    }

    const supported =
        typeof Intl.supportedValuesOf === 'function'
            ? Intl.supportedValuesOf('timeZone')
            : [];

    // Drop the old cache first so the offsets below are computed fresh.
    cache = null;

    const zones = supported
        .map((tz): KinetixZoneEntry => {
            const offset = kinetixTimezoneOffsetFor(tz) ?? {
                label: 'UTC+00:00',
                minutes: 0,
            };

            return {
                value: tz,
                region: tz.split('/')[0],
                name: tz.split('/').pop()!.replace(/_/g, ' '),
                offset: offset.label,
                offsetMinutes: offset.minutes,
            };
        })
        .sort(
            (a, b) =>
                a.offsetMinutes - b.offsetMinutes ||
                a.name.localeCompare(b.name),
        );

    cache = {
        bucket,
        zones,
        byValue: new Map(zones.map((zone) => [zone.value, zone])),
    };

    return zones;
}
