/**
 * Keyboard entry for date and time fields: a format like `dd/mm/yyyy` becomes
 * a mask the reader types into — digits only, separators inserted for them —
 * and the text converts to and from the ISO value the field stores.
 *
 * Tokens: `dd` day, `mm` month and `yyyy` / `yy` year in the date part;
 * `HH` (24h) / `hh` (12h) hour, `mm` minutes and `a` (AM/PM) in the time part
 * — the part after the first space, or after an hour token. Anything else is a
 * literal separator.
 */

export type KinetixDateKind = 'date' | 'datetime' | 'time' | 'month';

export type KinetixMaskSegment =
    | { type: 'literal'; text: string }
    | {
          type:
              | 'day'
              | 'month'
              | 'year'
              | 'year2'
              | 'hour'
              | 'hour12'
              | 'minute';
          length: number;
      }
    | { type: 'meridiem'; length: 2 };

const TOKENS: Array<[string, KinetixMaskSegment['type'], number]> = [
    ['yyyy', 'year', 4],
    ['yy', 'year2', 2],
    ['dd', 'day', 2],
    ['HH', 'hour', 2],
    ['hh', 'hour12', 2],
    ['mm', 'month', 2],
    ['a', 'meridiem', 2],
];

/**
 * The default format for a kind, in the order a locale writes dates, on the
 * field's clock (12/24h; the locale's when the field doesn't say).
 */
export function defaultDateFormat(
    kind: KinetixDateKind,
    locale?: string | null,
    hour12?: boolean | null,
): string {
    const parts = new Intl.DateTimeFormat(locale ?? undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(2026, 10, 22));
    const separator =
        parts.find((part) => part.type === 'literal')?.value.trim() || '/';
    const order = parts
        .filter((part) => ['day', 'month', 'year'].includes(part.type))
        .map((part) => part.type);
    const token = { day: 'dd', month: 'mm', year: 'yyyy' } as Record<
        string,
        string
    >;
    const date = order.map((type) => token[type]).join(separator);
    // The field's own clock wins; the locale's is the fallback.
    const twelve =
        hour12 ??
        new Intl.DateTimeFormat(locale ?? undefined, {
            hour: 'numeric',
        }).resolvedOptions().hour12 === true;
    const time = twelve ? 'hh:mm a' : 'HH:mm';

    if (kind === 'time') {
        return time;
    }

    if (kind === 'month') {
        return order
            .filter((type) => type !== 'day')
            .map((type) => token[type])
            .join(separator);
    }

    return kind === 'datetime' ? `${date} ${time}` : date;
}

/** A format split into the segments the mask fills. */
export function parseDateFormat(format: string): KinetixMaskSegment[] {
    const segments: KinetixMaskSegment[] = [];
    let inTime = false;
    let i = 0;

    while (i < format.length) {
        const token = TOKENS.find(([text]) => format.startsWith(text, i));

        if (token) {
            const [text, , length] = token;
            let type = token[1];

            if (type === 'hour' || type === 'hour12') {
                inTime = true;
            } else if (type === 'month' && inTime) {
                type = 'minute';
            }

            segments.push(
                type === 'meridiem'
                    ? { type: 'meridiem', length: 2 }
                    : ({ type, length } as KinetixMaskSegment),
            );
            i += text.length;

            continue;
        }

        if (format[i] === ' ') {
            inTime = true;
        }

        const last = segments[segments.length - 1];

        if (last?.type === 'literal') {
            last.text += format[i];
        } else {
            segments.push({ type: 'literal', text: format[i] });
        }

        i++;
    }

    return segments;
}

/** The placeholder a mask shows: the format in capitals (DD/MM/YYYY). */
export function maskPlaceholder(segments: KinetixMaskSegment[]): string {
    const letter: Record<string, string> = {
        day: 'DD',
        month: 'MM',
        year: 'YYYY',
        year2: 'YY',
        hour: 'HH',
        hour12: 'HH',
        minute: 'MM',
        meridiem: 'AM',
    };

    return segments
        .map((segment) =>
            segment.type === 'literal' ? segment.text : letter[segment.type],
        )
        .join('');
}

/** The largest first digit that can't be followed by another (4 → 04). */
const PAD_ABOVE: Partial<Record<KinetixMaskSegment['type'], number>> = {
    day: 3,
    month: 1,
    hour: 2,
    hour12: 1,
    minute: 5,
};

/**
 * What the reader typed, re-laid on the mask: digits fill the numeric
 * segments in order, a/p the AM/PM one, and each separator is inserted once
 * the segment before it is full. A first digit no two-digit value can start
 * with is padded (`4` in a day → `04`).
 */
export function applyDateMask(
    typed: string,
    segments: KinetixMaskSegment[],
): string {
    const chars = Array.from(typed.toUpperCase()).filter((char) =>
        /[0-9AP]/.test(char),
    );
    let out = '';
    let index = 0;

    for (const segment of segments) {
        if (index >= chars.length) {
            break;
        }

        if (segment.type === 'literal') {
            out += segment.text;

            continue;
        }

        if (segment.type === 'meridiem') {
            while (index < chars.length && !/[AP]/.test(chars[index])) {
                index++;
            }

            if (index < chars.length) {
                out += `${chars[index++]}M`;
            }

            continue;
        }

        let value = '';

        while (value.length < segment.length && index < chars.length) {
            const char = chars[index++];

            if (!/[0-9]/.test(char)) {
                continue;
            }

            const ceiling = PAD_ABOVE[segment.type];

            if (
                value === '' &&
                segment.length === 2 &&
                ceiling !== undefined &&
                Number(char) > ceiling
            ) {
                value = `0${char}`;
            } else {
                value += char;
            }
        }

        out += value;

        if (value.length < segment.length) {
            return out;
        }
    }

    return out;
}

/** Whether the text fills every segment of the mask. */
export function isMaskComplete(
    text: string,
    segments: KinetixMaskSegment[],
): boolean {
    return (
        applyDateMask(text, segments) === text &&
        text.length === maskPlaceholder(segments).length
    );
}

const pad = (value: number, length = 2): string =>
    String(value).padStart(length, '0');

/**
 * The ISO value a complete text stands for — `Y-m-d`, `Y-m-dTH:i`, `H:i` or
 * `Y-m` — `null` for an empty or unfinished text, and `false` when it's
 * complete but no such date or time exists (31/02, 25:00).
 */
export function dateMaskToIso(
    text: string,
    segments: KinetixMaskSegment[],
    kind: KinetixDateKind,
): string | null | false {
    if (!isMaskComplete(text, segments)) {
        return null;
    }

    const values: Record<string, number> = {};
    let meridiem: 'AM' | 'PM' | null = null;
    let position = 0;

    for (const segment of segments) {
        if (segment.type === 'literal') {
            position += segment.text.length;

            continue;
        }

        const raw = text.slice(position, position + segment.length);
        position += segment.length;

        if (segment.type === 'meridiem') {
            meridiem = raw.startsWith('P') ? 'PM' : 'AM';
        } else {
            values[segment.type] = Number(raw);
        }
    }

    const year =
        values.year ?? (values.year2 !== undefined ? 2000 + values.year2 : NaN);
    let hour = values.hour ?? values.hour12 ?? 0;

    if (values.hour12 !== undefined) {
        if (values.hour12 < 1 || values.hour12 > 12) {
            return false;
        }

        hour = (values.hour12 % 12) + (meridiem === 'PM' ? 12 : 0);
    }

    const minute = values.minute ?? 0;

    if (hour > 23 || minute > 59) {
        return false;
    }

    const time = `${pad(hour)}:${pad(minute)}`;

    if (kind === 'time') {
        return time;
    }

    const month = values.month;

    if (!month || month > 12 || Number.isNaN(year)) {
        return false;
    }

    if (kind === 'month') {
        return `${pad(year, 4)}-${pad(month)}`;
    }

    const day = values.day;
    const daysInMonth = new Date(year, month, 0).getDate();

    if (!day || day > daysInMonth) {
        return false;
    }

    const date = `${pad(year, 4)}-${pad(month)}-${pad(day)}`;

    return kind === 'datetime' ? `${date}T${time}` : date;
}

/** The text an ISO value shows in the mask (empty for no value). */
export function isoToDateMask(
    iso: string | null | undefined,
    segments: KinetixMaskSegment[],
): string {
    if (!iso) {
        return '';
    }

    const match =
        /^(?:(\d{4})-(\d{2})(?:-(\d{2}))?)?(?:[T ]?(\d{2}):(\d{2}))?/.exec(iso);

    if (!match) {
        return '';
    }

    const [, year, month, day, hour, minute] = match;
    const hours = Number(hour ?? 0);

    return segments
        .map((segment) => {
            switch (segment.type) {
                case 'literal':
                    return segment.text;
                case 'day':
                    return day ?? '';
                case 'month':
                    return month ?? '';
                case 'year':
                    return year ?? '';
                case 'year2':
                    return (year ?? '').slice(2);
                case 'hour':
                    return hour ?? '';
                case 'hour12':
                    return pad(hours % 12 || 12);
                case 'minute':
                    return minute ?? '';
                case 'meridiem':
                    return hours >= 12 ? 'PM' : 'AM';
            }
        })
        .join('');
}
