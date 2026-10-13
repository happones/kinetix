/**
 * What a field's validation rules ask of its value, as the server serialised
 * them (`FieldConstraints` → `comp.constraints`), turned into requirements the
 * form can show and check before the server does.
 *
 * Each requirement carries its hint (the sentence under the field, "Letters,
 * numbers, dashes and underscores") and its check. A violation reads as that
 * same hint, so the message says what to do, not just that something is
 * wrong. The server validates everything again: these checks are loose where
 * the exact rule is the server's (`email`, `url`), and a rule the browser
 * can't run has no check at all.
 */

type Bounds = { min?: number; max?: number; exact?: number };

export interface KinetixFieldConstraints {
    kind: 'string' | 'numeric' | 'array' | 'file';
    required?: boolean;
    length?: Bounds;
    range?: Bounds & { gt?: number; lt?: number };
    items?: Bounds;
    kilobytes?: Bounds;
    integer?: boolean;
    step?: number;
    decimals?: { min: number; max: number };
    digits?: { min?: number; max?: number };
    charset?: 'alpha' | 'alpha_num' | 'alpha_dash' | 'ascii';
    case?: 'lower' | 'upper';
    startsWith?: string[];
    endsWith?: string[];
    notStartsWith?: string[];
    notEndsWith?: string[];
    notIn?: string[];
    pattern?: { source: string; flags: string };
    notPattern?: { source: string; flags: string };
    format?: string;
    distinct?: boolean | 'ignore_case';
    keys?: string[];
    dates?: { op: string; date?: string; field?: string }[];
    compare?: { op: string; field: string }[];
    confirmed?: boolean;
    password?: {
        min: number;
        letters: boolean;
        mixedCase: boolean;
        numbers: boolean;
        symbols: boolean;
        uncompromised: boolean;
    };
    fileTypes?: string[];
    mimeTypes?: string[];
    image?: boolean;
    dimensions?: Record<string, number>;
}

export type Translate = (
    key: string,
    params?: Record<string, unknown>,
) => string;

/** The label of another field, for "At least Min price". */
export type LabelOf = (field: string) => string;

export interface Requirement {
    /** The sentence under the field, or null when the field already shows it. */
    hint: string | null;
    /** True when the value meets it; absent when only the server can tell. */
    check?: (value: unknown, values: Record<string, unknown>) => boolean;
    /** Shown as met (a confirmation that matches). */
    confirms?: boolean;
    /**
     * A requirement a dedicated input lists itself (`password`: the password
     * input's checklist), so the hints under the field leave it out.
     */
    part?: 'password' | 'format';
}

export interface RequirementOptions {
    /** A character counter shows the length limit, so its hint is left out. */
    counter?: boolean;
    /** A date or locale for formatting literal dates. */
    locale?: string;
}

const isBlank = (value: unknown): boolean =>
    value === null ||
    value === undefined ||
    value === '' ||
    (Array.isArray(value) && value.length === 0);

/** Length in characters, as `mb_strlen` counts them. */
const length = (value: unknown): number => [...String(value)].length;

const asNumber = (value: unknown): number | null => {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);

        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
};

const CHARSETS: Record<string, RegExp> = {
    alpha: /^[\p{L}\p{M}]+$/u,
    alpha_num: /^[\p{L}\p{M}\p{N}]+$/u,
    alpha_dash: /^[\p{L}\p{M}\p{N}_-]+$/u,
    ascii: /^[\x00-\x7F]*$/,
};

const isIpv4 = (value: string): boolean =>
    /^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/.test(
        value,
    );

const isIpv6 = (value: string): boolean => {
    if (!value.includes(':') || /[^0-9a-f:.]/i.test(value)) {
        return false;
    }

    try {
        return new URL(`http://[${value}]`).hostname !== '';
    } catch {
        return false;
    }
};

const isTimezone = (value: string): boolean => {
    try {
        new Intl.DateTimeFormat('en', { timeZone: value });

        return true;
    } catch {
        return false;
    }
};

const FORMAT_CHECKS: Record<string, (value: string) => boolean> = {
    email: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v),
    url: (v) => {
        try {
            return new URL(v).protocol !== '';
        } catch {
            return false;
        }
    },
    ip: (v) => isIpv4(v) || isIpv6(v),
    ipv4: isIpv4,
    ipv6: isIpv6,
    mac: (v) =>
        /^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/i.test(v) ||
        /^([0-9a-f]{4}\.){2}[0-9a-f]{4}$/i.test(v),
    uuid: (v) =>
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            v,
        ),
    ulid: (v) => /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i.test(v),
    hex_color: (v) => /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(v),
    json: (v) => {
        try {
            JSON.parse(v);

            return true;
        } catch {
            return false;
        }
    },
    timezone: isTimezone,
};

/** A date value or target as a time, `today`/`tomorrow` at local midnight. */
const toTime = (value: unknown): number | null => {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const midnight = new Date();
    midnight.setHours(0, 0, 0, 0);
    const day = 86_400_000;

    switch (value.toLowerCase()) {
        case 'now':
            return Date.now();
        case 'today':
            return midnight.getTime();
        case 'tomorrow':
            return midnight.getTime() + day;
        case 'yesterday':
            return midnight.getTime() - day;
    }

    // A bare date is local midnight, as the server reads it.
    const time = /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? new Date(`${value}T00:00:00`).getTime()
        : new Date(value).getTime();

    return Number.isNaN(time) ? null : time;
};

const DATE_OPS: Record<string, (a: number, b: number) => boolean> = {
    after: (a, b) => a > b,
    after_or_equal: (a, b) => a >= b,
    before: (a, b) => a < b,
    before_or_equal: (a, b) => a <= b,
    equals: (a, b) => new Date(a).toDateString() === new Date(b).toDateString(),
};

const COMPARE_OPS: Record<string, (a: number, b: number) => boolean> = {
    gt: (a, b) => a > b,
    gte: (a, b) => a >= b,
    lt: (a, b) => a < b,
    lte: (a, b) => a <= b,
};

const decimalPlaces = (value: unknown): number =>
    String(value).split('.')[1]?.length ?? 0;

const sizeText = (kilobytes: number): string =>
    kilobytes >= 1024
        ? `${Math.round((kilobytes / 1024) * 10) / 10} MB`
        : `${kilobytes} KB`;

const regex = (pattern: { source: string; flags: string }): RegExp | null => {
    try {
        return new RegExp(pattern.source, pattern.flags);
    } catch {
        return null;
    }
};

/**
 * The requirements a field's constraints set, in the order they're shown.
 */
export function fieldRequirements(
    c: KinetixFieldConstraints | null | undefined,
    t: Translate,
    labelOf: LabelOf,
    options: RequirementOptions = {},
): Requirement[] {
    if (!c) {
        return [];
    }

    const out: Requirement[] = [];
    const or = (values: string[]): string =>
        values.map((v) => `"${v}"`).join(t('kinetix.rule_or'));

    const dateText = (target: { date?: string; field?: string }): string => {
        if (target.field) {
            return labelOf(target.field);
        }

        const date = target.date ?? '';

        if (['today', 'tomorrow', 'yesterday', 'now'].includes(date)) {
            return t(`kinetix.rule_date_${date}`);
        }

        const time = toTime(date);

        return time === null
            ? date
            : new Intl.DateTimeFormat(options.locale, {
                  dateStyle: 'medium',
              }).format(time);
    };

    // Length (strings), value (numbers), count (arrays).
    const bounded = (
        bounds: Bounds & { gt?: number; lt?: number },
        prefix: string,
        measure: (value: unknown) => number | null,
        hidden = false,
    ): void => {
        const { min, max, exact, gt, lt } = bounds;
        const within = (value: unknown): boolean => {
            const n = measure(value);

            return (
                n === null ||
                ((exact === undefined || n === exact) &&
                    (min === undefined || n >= min) &&
                    (max === undefined || n <= max) &&
                    (gt === undefined || n > gt) &&
                    (lt === undefined || n < lt))
            );
        };

        let hint: string | null = null;

        if (exact !== undefined) {
            hint = t(`kinetix.rule_${prefix}_exact`, { size: exact });
        } else if (min !== undefined && max !== undefined) {
            hint = t(`kinetix.rule_${prefix}_between`, { min, max });
        } else if (min !== undefined) {
            hint = t(`kinetix.rule_${prefix}_min`, { min });
        } else if (max !== undefined) {
            hint = hidden ? null : t(`kinetix.rule_${prefix}_max`, { max });
        }

        if (gt !== undefined) {
            hint = [hint, t('kinetix.rule_number_gt', { value: gt })]
                .filter(Boolean)
                .join(' · ');
        }

        if (lt !== undefined) {
            hint = [hint, t('kinetix.rule_number_lt', { value: lt })]
                .filter(Boolean)
                .join(' · ');
        }

        out.push({ hint, check: within });
    };

    if (c.length) {
        bounded(
            c.length,
            'length',
            (v) => length(v),
            !!options.counter && c.length.min === undefined,
        );
    }

    if (c.range) {
        bounded(c.range, 'number', asNumber);
    }

    if (c.items) {
        bounded(c.items, 'items', (v) =>
            Array.isArray(v)
                ? v.length
                : v && typeof v === 'object'
                  ? Object.keys(v).length
                  : null,
        );
    }

    if (c.integer) {
        out.push({
            hint: t('kinetix.rule_integer'),
            check: (v) => /^-?\d+$/.test(String(v).trim()),
        });
    }

    if (c.step !== undefined) {
        const step = c.step;

        out.push({
            hint: t('kinetix.rule_step', { step }),
            check: (v) => {
                const n = asNumber(v);

                if (n === null || step === 0) {
                    return true;
                }

                const ratio = n / step;

                return Math.abs(ratio - Math.round(ratio)) < 1e-9;
            },
        });
    }

    if (c.decimals) {
        const { min, max } = c.decimals;

        out.push({
            hint:
                min === max
                    ? t('kinetix.rule_decimals_exact', { places: max })
                    : t('kinetix.rule_decimals_between', { min, max }),
            check: (v) => {
                const places = decimalPlaces(v);

                return places >= min && places <= max;
            },
        });
    }

    if (c.digits) {
        const { min, max } = c.digits;

        out.push({
            hint:
                min !== undefined && min === max
                    ? t('kinetix.rule_digits_exact', { digits: min })
                    : min !== undefined && max !== undefined
                      ? t('kinetix.rule_digits_between', { min, max })
                      : min !== undefined
                        ? t('kinetix.rule_digits_min', { min })
                        : t('kinetix.rule_digits_max', { max }),
            check: (v) => {
                const text = String(v);

                return (
                    /^\d+$/.test(text) &&
                    (min === undefined || text.length >= min) &&
                    (max === undefined || text.length <= max)
                );
            },
        });
    }

    if (c.charset) {
        const charset = CHARSETS[c.charset];

        out.push({
            hint: t(`kinetix.rule_charset_${c.charset}`),
            check: (v) => charset.test(String(v)),
        });
    }

    if (c.case) {
        const lower = c.case === 'lower';

        out.push({
            hint: t(
                lower ? 'kinetix.rule_lowercase' : 'kinetix.rule_uppercase',
            ),
            check: (v) =>
                String(v) ===
                (lower ? String(v).toLowerCase() : String(v).toUpperCase()),
        });
    }

    const affix = (
        values: string[] | undefined,
        key: string,
        test: (text: string, part: string) => boolean,
        negate: boolean,
    ): void => {
        if (!values?.length) {
            return;
        }

        out.push({
            hint: t(key, { values: or(values) }),
            check: (v) => {
                const hit = values.some((part) => test(String(v), part));

                return negate ? !hit : hit;
            },
        });
    };

    affix(
        c.startsWith,
        'kinetix.rule_starts_with',
        (s, p) => s.startsWith(p),
        false,
    );
    affix(c.endsWith, 'kinetix.rule_ends_with', (s, p) => s.endsWith(p), false);
    affix(
        c.notStartsWith,
        'kinetix.rule_not_starts_with',
        (s, p) => s.startsWith(p),
        true,
    );
    affix(
        c.notEndsWith,
        'kinetix.rule_not_ends_with',
        (s, p) => s.endsWith(p),
        true,
    );

    if (c.notIn?.length) {
        const notIn = c.notIn;

        out.push({
            hint: t('kinetix.rule_not_in', { values: or(notIn) }),
            check: (v) => !notIn.includes(String(v)),
        });
    }

    if (c.format && FORMAT_CHECKS[c.format]) {
        const check = FORMAT_CHECKS[c.format];

        out.push({
            part: 'format',
            // An email field says what it wants by itself.
            hint:
                c.format === 'email'
                    ? null
                    : t(`kinetix.rule_format_${c.format}`),
            check: (v) => check(String(v).trim()),
        });
    }

    for (const [pattern, negate] of [
        [c.pattern, false],
        [c.notPattern, true],
    ] as const) {
        const compiled = pattern ? regex(pattern) : null;

        if (compiled) {
            out.push({
                hint: null,
                check: (v) => compiled.test(String(v)) !== negate,
            });
        }
    }

    if (c.distinct) {
        out.push({
            hint: t('kinetix.rule_distinct'),
            check: (v) =>
                !Array.isArray(v) ||
                new Set(
                    v.map((item) =>
                        c.distinct === 'ignore_case'
                            ? JSON.stringify(item).toLowerCase()
                            : JSON.stringify(item),
                    ),
                ).size === v.length,
        });
    }

    for (const date of c.dates ?? []) {
        const op = DATE_OPS[date.op];

        if (!op) {
            continue;
        }

        out.push({
            hint: t(`kinetix.rule_date_${date.op}`, { date: dateText(date) }),
            check: (v, values) => {
                const value = toTime(v);
                const target = toTime(
                    date.field ? values[date.field] : date.date,
                );

                return value === null || target === null || op(value, target);
            },
        });
    }

    for (const compare of c.compare ?? []) {
        const label = labelOf(compare.field);
        const other = (values: Record<string, unknown>) =>
            values[compare.field];

        if (compare.op === 'same' || compare.op === 'different') {
            const same = compare.op === 'same';

            out.push({
                hint: t(same ? 'kinetix.rule_same' : 'kinetix.rule_different', {
                    field: label,
                }),
                confirms: same,
                check: (v, values) =>
                    isBlank(other(values)) ||
                    (String(v) === String(other(values))) === same,
            });

            continue;
        }

        const op = COMPARE_OPS[compare.op];

        if (!op) {
            continue;
        }

        out.push({
            hint: t(`kinetix.rule_compare_${compare.op}`, { field: label }),
            check: (v, values) => {
                const a = c.kind === 'numeric' ? asNumber(v) : length(v);
                const b =
                    c.kind === 'numeric'
                        ? asNumber(other(values))
                        : isBlank(other(values))
                          ? null
                          : length(other(values));

                return a === null || b === null || op(a, b);
            },
        });
    }

    if (c.password) {
        const p = c.password;

        out.push({
            part: 'password',
            hint: t('kinetix.rule_password_min', { min: p.min }),
            check: (v) => length(v) >= p.min,
        });

        if (p.mixedCase) {
            out.push({
                part: 'password',
                hint: t('kinetix.rule_password_mixed_case'),
                check: (v) =>
                    /\p{Ll}/u.test(String(v)) && /\p{Lu}/u.test(String(v)),
            });
        } else if (p.letters) {
            out.push({
                part: 'password',
                hint: t('kinetix.rule_password_letters'),
                check: (v) => /\p{L}/u.test(String(v)),
            });
        }

        if (p.numbers) {
            out.push({
                part: 'password',
                hint: t('kinetix.rule_password_numbers'),
                check: (v) => /\p{N}/u.test(String(v)),
            });
        }

        if (p.symbols) {
            out.push({
                part: 'password',
                hint: t('kinetix.rule_password_symbols'),
                check: (v) => /[\p{Z}\p{S}\p{P}]/u.test(String(v)),
            });
        }

        if (p.uncompromised) {
            out.push({
                part: 'password',
                hint: t('kinetix.rule_password_uncompromised'),
            });
        }
    }

    if (c.kind === 'file') {
        const parts: string[] = [];
        const types = [
            ...(c.fileTypes ?? []).map((type) => type.toUpperCase()),
            ...(c.mimeTypes ?? []),
        ];

        if (types.length) {
            parts.push(types.join(', '));
        } else if (c.image) {
            parts.push(t('kinetix.rule_file_image'));
        }

        if (c.kilobytes?.max !== undefined) {
            parts.push(
                t('kinetix.rule_file_max', { size: sizeText(c.kilobytes.max) }),
            );
        }

        const d = c.dimensions ?? {};

        if (d.minWidth || d.minHeight) {
            parts.push(
                t('kinetix.rule_file_min_dimensions', {
                    width: d.minWidth ?? '…',
                    height: d.minHeight ?? '…',
                }),
            );
        }

        if (d.maxWidth || d.maxHeight) {
            parts.push(
                t('kinetix.rule_file_max_dimensions', {
                    width: d.maxWidth ?? '…',
                    height: d.maxHeight ?? '…',
                }),
            );
        }

        if (d.ratio) {
            parts.push(t('kinetix.rule_file_ratio', { ratio: d.ratio }));
        }

        if (parts.length) {
            out.push({ hint: parts.join(' · ') });
        }
    }

    return out;
}

/** The hints a field shows under it, but for the parts its input lists. */
export const requirementHints = (
    requirements: Requirement[],
    listedByInput: string[] = [],
): string[] =>
    requirements.flatMap((r) =>
        r.hint && !(r.part && listedByInput.includes(r.part)) ? [r.hint] : [],
    );

const COMMON_PIECES = [
    '123',
    '234',
    '345',
    '456',
    '567',
    '678',
    '789',
    '890',
    'abc',
    'qwerty',
    'asdf',
    'zxcv',
    'password',
    'pass',
    'word',
    'admin',
    'letmein',
    'welcome',
    'iloveyou',
];

/**
 * A rough strength for a password, 0 (empty) to 4: how many guesses its
 * length and character variety allow, less for repeats and the patterns
 * people reach for first. A guide next to the policy, not a rule.
 */
export function passwordStrength(value: string): 0 | 1 | 2 | 3 | 4 {
    if (!value) {
        return 0;
    }

    let pool = 0;

    if (/[a-z]/.test(value)) {
        pool += 26;
    }

    if (/[A-Z]/.test(value)) {
        pool += 26;
    }

    if (/\d/.test(value)) {
        pool += 10;
    }

    if (/[^a-zA-Z\d]/.test(value)) {
        pool += 33;
    }

    const chars = [...value];
    const distinct = new Set(chars).size;
    // Repeats add little: "aaaaaaaa" is not eight characters' worth.
    const effective = Math.min(chars.length, distinct * 2);
    let bits = Math.log2(Math.max(pool, 2)) * effective;

    // Each piece people reach for first costs a share of the guesses.
    const lower = value.toLowerCase();

    for (const piece of COMMON_PIECES) {
        if (lower.includes(piece)) {
            bits -= 12;
        }
    }

    return bits < 28 ? 1 : bits < 40 ? 2 : bits < 60 ? 3 : 4;
}

/**
 * The first requirement the value misses, as its hint (or the generic format
 * message for a pattern), or null. An empty value is the server's to judge
 * (`required` and friends), so it passes here.
 */
export function requirementViolation(
    requirements: Requirement[],
    value: unknown,
    values: Record<string, unknown>,
    t: Translate,
): string | null {
    if (isBlank(value)) {
        return null;
    }

    for (const requirement of requirements) {
        if (requirement.check && !requirement.check(value, values)) {
            return requirement.hint ?? t('kinetix.rule_pattern');
        }
    }

    return null;
}

/** Whether a confirmation requirement is met: the two values match. */
export const confirmsMatch = (
    requirements: Requirement[],
    value: unknown,
    values: Record<string, unknown>,
): boolean =>
    !isBlank(value) &&
    requirements.some(
        (r) => r.confirms && r.check !== undefined && r.check(value, values),
    );

/** A character counter for a string with a maximum length, or null. */
export function characterCounter(
    c: KinetixFieldConstraints | null | undefined,
    value: unknown,
): { count: number; max: number } | null {
    const max =
        c?.kind === 'string' ? (c.length?.max ?? c.length?.exact) : undefined;

    return max === undefined
        ? null
        : { count: isBlank(value) ? 0 : length(value), max };
}

/**
 * Input attributes that fit the rules: the keyboard a phone opens, and no
 * autocorrect or capitalization on codes. Never `maxlength`: a hard stop
 * silently drops pasted text, and the counter says it instead.
 */
export function constraintInputAttributes(
    c: KinetixFieldConstraints | null | undefined,
): Record<string, string> {
    if (!c) {
        return {};
    }

    const attrs: Record<string, string> = {};

    if (c.kind === 'numeric' || c.digits) {
        attrs.inputmode =
            c.integer || c.digits || c.decimals?.max === 0
                ? 'numeric'
                : 'decimal';
    } else if (c.format === 'email') {
        attrs.inputmode = 'email';
    } else if (c.format === 'url') {
        attrs.inputmode = 'url';
    }

    const isCode =
        !!c.charset ||
        !!c.case ||
        !!c.digits ||
        (c.format !== undefined && c.format !== 'email' && c.format !== 'url');

    if (isCode || c.format === 'email' || c.format === 'url') {
        attrs.autocapitalize = c.case === 'upper' ? 'characters' : 'none';
        attrs.autocorrect = 'off';
        attrs.spellcheck = 'false';
    }

    return attrs;
}

/**
 * The value with the case `lowercase`/`uppercase` asks for, applied as the
 * user types so they never have to retype it.
 */
export const applyConstraintCase = (
    c: KinetixFieldConstraints | null | undefined,
    value: string,
): string =>
    c?.case === 'lower'
        ? value.toLowerCase()
        : c?.case === 'upper'
          ? value.toUpperCase()
          : value;

/** Formats written as codes: shown in a monospace font, with a valid tick. */
export const CODE_FORMATS = [
    'ip',
    'ipv4',
    'ipv6',
    'mac',
    'uuid',
    'ulid',
    'hex_color',
];

/** Whether a value has the format, or null when there's nothing to judge. */
export function formatHolds(
    format: string | undefined,
    value: unknown,
): boolean | null {
    const check = format ? FORMAT_CHECKS[format] : undefined;

    return !check || isBlank(value) ? null : check(String(value).trim());
}

const pad = (n: number): string => String(n).padStart(2, '0');

const localDate = (time: number): string => {
    const d = new Date(time);

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const localDateTime = (time: number): string => {
    const d = new Date(time);

    return `${localDate(time)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/**
 * The earliest and latest value a date field's rules allow, for its picker:
 * `after:today` starts tomorrow, `before_or_equal:ends_on` stops on the day
 * that field holds. The tightest bound wins, a fixed `minValue`/`maxValue`
 * included. Dates as `YYYY-MM-DD`; with `withTime`, `YYYY-MM-DDTHH:mm`.
 */
export function dateBounds(
    c: KinetixFieldConstraints | null | undefined,
    values: Record<string, unknown>,
    fixed: { min?: string | null; max?: string | null } = {},
    withTime = false,
): { min: string | null; max: string | null } {
    let min = fixed.min ? toTime(fixed.min) : null;
    let max = fixed.max ? toTime(fixed.max) : null;
    const day = 86_400_000;

    for (const date of c?.dates ?? []) {
        const target = toTime(date.field ? values[date.field] : date.date);

        if (target === null) {
            continue;
        }

        // A date field can't pick "later today": strictly after a day is the
        // next one. A date-time field can, a minute on.
        const after = withTime ? target + 60_000 : target + day;
        const before = withTime ? target - 60_000 : target - day;

        const lower = { after, after_or_equal: target, equals: target }[
            date.op as 'after' | 'after_or_equal' | 'equals'
        ];
        const upper = { before, before_or_equal: target, equals: target }[
            date.op as 'before' | 'before_or_equal' | 'equals'
        ];

        if (lower !== undefined) {
            min = min === null ? lower : Math.max(min, lower);
        }

        if (upper !== undefined) {
            max = max === null ? upper : Math.min(max, upper);
        }
    }

    const write = withTime ? localDateTime : localDate;

    return {
        min: min === null ? null : write(min),
        max: max === null ? null : write(max),
    };
}

/** A file's pixel size, or null when the browser can't read it. */
const imageSize = (
    file: File,
): Promise<{ width: number; height: number } | null> =>
    new Promise((resolve) => {
        if (typeof URL === 'undefined' || !URL.createObjectURL) {
            resolve(null);

            return;
        }

        const url = URL.createObjectURL(file);
        const image = new Image();
        const done = (size: { width: number; height: number } | null) => {
            URL.revokeObjectURL(url);
            resolve(size);
        };

        image.onload = () =>
            done({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => done(null);
        image.src = url;
    });

/** `jpg` and `jpeg` are the same type, as the server reads them. */
const extensionsOf = (types: string[]): string[] =>
    types.some((type) => type === 'jpg' || type === 'jpeg')
        ? [...new Set([...types, 'jpg', 'jpeg'])]
        : types;

const mimeMatches = (type: string, accepted: string): boolean =>
    accepted.endsWith('/*')
        ? type.startsWith(accepted.slice(0, -1))
        : type === accepted;

/**
 * Why a file the user picked can't be uploaded under the field's rules — its
 * type, its size, its pixel dimensions — or null. Checked before the upload,
 * so a 40 MB photo isn't sent only to be refused; the server checks again.
 */
export async function fileProblem(
    file: File,
    c: KinetixFieldConstraints | null | undefined,
    t: Translate,
    extra: { maxKilobytes?: number | null; image?: boolean } = {},
): Promise<string | null> {
    const extension = file.name.includes('.')
        ? file.name.split('.').pop()!.toLowerCase()
        : '';
    const extensions = extensionsOf(c?.fileTypes ?? []);
    const mimeTypes = c?.mimeTypes ?? [];
    const declared = [...(c?.fileTypes ?? []), ...mimeTypes];

    if (
        declared.length &&
        !extensions.includes(extension) &&
        !mimeTypes.some((type) => mimeMatches(file.type, type))
    ) {
        return t('kinetix.upload_rejected_type', {
            file: file.name,
            types: declared.map((type) => type.toUpperCase()).join(', '),
        });
    }

    if (
        (c?.image || extra.image) &&
        !declared.length &&
        !file.type.startsWith('image/')
    ) {
        return t('kinetix.upload_rejected_type', {
            file: file.name,
            types: t('kinetix.rule_file_image'),
        });
    }

    const limits = [c?.kilobytes?.max, extra.maxKilobytes].filter(
        (limit): limit is number => typeof limit === 'number',
    );
    const maxKilobytes = limits.length ? Math.min(...limits) : null;
    const kilobytes = file.size / 1024;

    if (maxKilobytes !== null && kilobytes > maxKilobytes) {
        return t('kinetix.upload_rejected_size', {
            file: file.name,
            size: sizeText(Math.ceil(kilobytes)),
            max: sizeText(maxKilobytes),
        });
    }

    if (c?.kilobytes?.min !== undefined && kilobytes < c.kilobytes.min) {
        return t('kinetix.upload_rejected_small', {
            file: file.name,
            min: sizeText(c.kilobytes.min),
        });
    }

    const d = c?.dimensions;

    if (d && Object.keys(d).length && file.type.startsWith('image/')) {
        const size = await imageSize(file);

        if (size) {
            const { width, height } = size;
            const fits =
                (d.width === undefined || width === d.width) &&
                (d.height === undefined || height === d.height) &&
                (d.minWidth === undefined || width >= d.minWidth) &&
                (d.minHeight === undefined || height >= d.minHeight) &&
                (d.maxWidth === undefined || width <= d.maxWidth) &&
                (d.maxHeight === undefined || height <= d.maxHeight) &&
                (d.ratio === undefined ||
                    Math.abs(width / height - d.ratio) <
                        1 / Math.max(width, height, 100));

            if (!fits) {
                return t('kinetix.upload_rejected_dimensions', {
                    file: file.name,
                    width,
                    height,
                });
            }
        }
    }

    return null;
}

/** The `accept` attribute a file input gets from the field's rules. */
export function fileAccept(
    c: KinetixFieldConstraints | null | undefined,
): string | undefined {
    const types = [
        ...extensionsOf(c?.fileTypes ?? []).map((type) => `.${type}`),
        ...(c?.mimeTypes ?? []),
    ];

    if (types.length) {
        return types.join(',');
    }

    return c?.image ? 'image/*' : undefined;
}
