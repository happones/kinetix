/**
 * The value-generation engine shared by the `<KinetixGenerator>` standalone
 * component and the `generator-input` form field. Pure logic — no DOM, no Vue —
 * so it's trivially testable and reusable.
 *
 * Randomness comes from `crypto.getRandomValues` with REJECTION SAMPLING, so
 * the character distribution is uniform (no modulo bias).
 *
 * The engine is built from a handful of PRIMITIVES (`strategy`) that the
 * presets compose by DATA, not code — adding a preset is one entry in
 * {@link GENERATOR_PRESETS}, never a new branch:
 *   - charset   — N chars from an alphabet (passwords, PINs, hex, nanoid, …);
 *   - mask      — a template of class tokens (`#` digit, `A` upper, `a` lower,
 *                 `*` alnum, `H` hex) with literals kept (license keys, OTPs);
 *   - words     — memorable `adjective-noun-####` style handles/passphrases;
 *   - uuid      — RFC-4122 v4;
 *   - template  — a `{field}` pattern filled from sibling values (usernames).
 */

import {
    GENERATOR_ADJECTIVES,
    GENERATOR_NOUNS,
} from '@/composables/kinetixGeneratorWords';

export type GeneratorStrategy =
    | 'charset'
    | 'mask'
    | 'words'
    | 'uuid'
    | 'template';

export interface KinetixGeneratorConfig {
    /**
     * Legacy kind (password | pin | username) — still honoured. New configs use
     * `preset` and/or `strategy`.
     */
    kind?: 'password' | 'pin' | 'username';
    /** Named preset from {@link GENERATOR_PRESETS}. */
    preset?: string;
    /** Generation primitive; derived from the preset/kind when omitted. */
    strategy?: GeneratorStrategy;
    length?: number;
    // charset knobs
    lowercase?: boolean;
    uppercase?: boolean;
    digits?: boolean;
    symbols?: boolean;
    /** An explicit alphabet (overrides the class flags entirely). */
    alphabet?: string | null;
    symbolSet?: string | null;
    excludeAmbiguous?: boolean;
    /** Legacy PIN alphabet selector. */
    pinMode?: 'alpha' | 'alphanum' | 'numeric';
    // mask knobs
    mask?: string | null;
    // words knobs
    words?: number;
    wordSeparator?: string;
    appendDigits?: number;
    // template (username) knobs
    pattern?: string | null;
    separator?: string | null;
    /** Prefix glued before the generated value (e.g. `sk_` for an API key). */
    prefix?: string | null;
    // UI
    copyable?: boolean;
    revealable?: boolean;
}

const LOWER = 'abcdefghijklmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const DIGITS = '0123456789';
const HEX = '0123456789abcdef';
const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?';
const BASE62 = LOWER + UPPER + DIGITS;
// nanoid's url-safe alphabet.
const NANOID = BASE62 + '_-';
const AMBIGUOUS = /[O0oIl1|]/g;

/** A uniformly-random integer in [0, max) via rejection sampling. */
function randomInt(max: number): number {
    if (max <= 0) {
        return 0;
    }

    const limit = Math.floor(0xffffffff / max) * max;
    const buf = new Uint32Array(1);

    let n = 0;

    do {
        crypto.getRandomValues(buf);
        n = buf[0];
    } while (n >= limit);

    return n % max;
}

function pick<T>(items: readonly T[]): T {
    return items[randomInt(items.length)];
}

/** Pick `length` chars uniformly from `alphabet`. */
function randomString(alphabet: string, length: number): string {
    if (!alphabet) {
        return '';
    }

    let out = '';

    for (let i = 0; i < length; i++) {
        out += alphabet[randomInt(alphabet.length)];
    }

    return out;
}

/** The alphabet a charset config generates from. */
function charsetAlphabet(config: KinetixGeneratorConfig): string {
    if (config.alphabet) {
        return config.excludeAmbiguous
            ? config.alphabet.replace(AMBIGUOUS, '')
            : config.alphabet;
    }

    // Legacy PIN alphabet selector — only a PIN reads it. The form field ships
    // its `pinMode` default with every config, so honouring it for any other
    // kind turned passwords and usernames into digits.
    if (config.kind === 'pin' && config.pinMode) {
        const pin =
            config.pinMode === 'alpha'
                ? LOWER + UPPER
                : config.pinMode === 'alphanum'
                  ? BASE62
                  : DIGITS;

        return config.excludeAmbiguous ? pin.replace(AMBIGUOUS, '') : pin;
    }

    let alphabet = '';

    if (config.lowercase !== false) {
        alphabet += LOWER;
    }

    if (config.uppercase !== false) {
        alphabet += UPPER;
    }

    if (config.digits !== false) {
        alphabet += DIGITS;
    }

    if (config.symbols) {
        alphabet += config.symbolSet || SYMBOLS;
    }

    if (!alphabet) {
        alphabet = LOWER + DIGITS;
    }

    if (config.excludeAmbiguous) {
        alphabet = alphabet.replace(AMBIGUOUS, '');
    }

    return alphabet;
}

/**
 * The character classes a class-flag charset draws from (lowercase, uppercase,
 * digits, symbols — after excludeAmbiguous). Empty for an explicit alphabet or
 * a PIN, which make no per-class promise.
 */
function charsetClasses(config: KinetixGeneratorConfig): string[] {
    if (config.alphabet || config.kind === 'pin') {
        return [];
    }

    const strip = (chars: string) =>
        config.excludeAmbiguous ? chars.replace(AMBIGUOUS, '') : chars;
    const classes: string[] = [];

    if (config.lowercase !== false) {
        classes.push(strip(LOWER));
    }

    if (config.uppercase !== false) {
        classes.push(strip(UPPER));
    }

    if (config.digits !== false) {
        classes.push(strip(DIGITS));
    }

    if (config.symbols) {
        classes.push(strip(config.symbolSet || SYMBOLS));
    }

    return classes.filter((chars) => chars.length > 0);
}

/**
 * A charset value that uses EVERY enabled class at least once — what a
 * password policy ("mixed case, numbers and symbols") checks. Drawn by
 * rejection: whole strings are resampled until one qualifies, so every
 * qualifying string stays equally likely (forcing one character per class
 * into fixed positions would not). Too short to hold every class: plain draw.
 */
function charsetValue(config: KinetixGeneratorConfig, length: number): string {
    const alphabet = charsetAlphabet(config);
    const classes = charsetClasses(config);

    if (classes.length < 2 || length < classes.length) {
        return randomString(alphabet, length);
    }

    for (;;) {
        const value = randomString(alphabet, length);

        if (
            classes.every((chars) => [...value].some((c) => chars.includes(c)))
        ) {
            return value;
        }
    }
}

/**
 * The handle a `{field}` pattern resolves to from sibling values, or `''`
 * when they don't fill it yet. What a username field follows live.
 */
export function handleFromPattern(
    config: KinetixGeneratorConfig,
    values: Record<string, unknown>,
): string {
    const resolved = resolveGeneratorConfig(config);

    return resolved.strategy === 'template' && resolved.pattern
        ? usernameFromPattern(
              resolved.pattern,
              values,
              resolved.separator || '.',
          )
        : '';
}

/** Fill a mask template: class tokens become random chars, literals stay. */
function fromMask(mask: string): string {
    let out = '';

    for (const ch of mask) {
        switch (ch) {
            case '#':
                out += DIGITS[randomInt(10)];
                break;
            case 'A':
                out += UPPER[randomInt(26)];
                break;
            case 'a':
                out += LOWER[randomInt(26)];
                break;
            case '*':
                out += BASE62[randomInt(BASE62.length)];
                break;
            case 'H':
                out += HEX[randomInt(16)].toUpperCase();
                break;
            default:
                out += ch; // literal (separators, fixed chars)
        }
    }

    return out;
}

/** RFC-4122 v4 UUID. */
function uuidV4(): string {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const hex = [...b].map((n) => n.toString(16).padStart(2, '0'));

    return (
        hex.slice(0, 4).join('') +
        '-' +
        hex.slice(4, 6).join('') +
        '-' +
        hex.slice(6, 8).join('') +
        '-' +
        hex.slice(8, 10).join('') +
        '-' +
        hex.slice(10, 16).join('')
    );
}

function memorableWords(config: KinetixGeneratorConfig): string {
    const count = Math.max(1, config.words ?? 2);
    const sep = config.wordSeparator ?? '-';
    const parts: string[] = [];

    for (let i = 0; i < count; i++) {
        parts.push(
            i % 2 === 0 ? pick(GENERATOR_ADJECTIVES) : pick(GENERATOR_NOUNS),
        );
    }

    let out = parts.join(sep);

    if (config.appendDigits && config.appendDigits > 0) {
        out += sep + randomString(DIGITS, config.appendDigits);
    }

    return out;
}

/** Normalize arbitrary text into a safe username/handle. */
export function normalizeHandle(text: string, separator = '.'): string {
    const sep = separator || '.';
    const escaped = sep.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return (text ?? '')
        .toString()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, sep)
        .replace(new RegExp(`${escaped}+`, 'g'), sep)
        .replace(new RegExp(`^${escaped}|${escaped}$`, 'g'), '');
}

function usernameFromPattern(
    pattern: string,
    values: Record<string, unknown>,
    separator: string,
): string {
    const filled = pattern.replace(/\{([^}]+)\}/g, (_, key: string) => {
        const v = values[key.trim()];

        return v === null || v === undefined ? '' : String(v);
    });

    return normalizeHandle(filled, separator);
}

/**
 * The preset catalog — the whole point of the "many presets + custom" design.
 * Each is just DATA composing a primitive; add one here and it's available
 * everywhere (field, standalone, the demo picker) with no engine change.
 */
export const GENERATOR_PRESETS: Record<string, KinetixGeneratorConfig> = {
    // Passwords
    'password-strong': {
        strategy: 'charset',
        length: 16,
        lowercase: true,
        uppercase: true,
        digits: true,
        symbols: true,
    },
    'password-simple': {
        strategy: 'charset',
        length: 14,
        lowercase: true,
        uppercase: true,
        digits: true,
        symbols: false,
        excludeAmbiguous: true,
    },
    // ~41 bits: two adjectives, two nouns and two digits.
    'password-memorable': {
        strategy: 'words',
        words: 4,
        wordSeparator: '-',
        appendDigits: 2,
    },
    // ~51 bits: three adjectives and three nouns.
    passphrase: { strategy: 'words', words: 6, wordSeparator: ' ' },
    // PINs / codes
    'pin-4': { strategy: 'charset', length: 4, alphabet: DIGITS },
    'pin-6': { strategy: 'charset', length: 6, alphabet: DIGITS },
    otp: { strategy: 'charset', length: 6, alphabet: DIGITS },
    'pin-alphanum': {
        strategy: 'charset',
        length: 6,
        alphabet: BASE62,
        excludeAmbiguous: true,
    },
    // Usernames / handles
    handle: { strategy: 'charset', length: 10, alphabet: LOWER + DIGITS },
    'handle-memorable': {
        strategy: 'words',
        words: 2,
        wordSeparator: '-',
        appendDigits: 2,
    },
    // Tokens / ids
    uuid: { strategy: 'uuid' },
    hex: { strategy: 'charset', length: 32, alphabet: HEX },
    'hex-64': { strategy: 'charset', length: 64, alphabet: HEX },
    nanoid: { strategy: 'charset', length: 21, alphabet: NANOID },
    'api-key': {
        strategy: 'charset',
        length: 40,
        alphabet: BASE62,
        prefix: 'sk_',
    },
    'license-key': { strategy: 'mask', mask: '****-****-****-****' },
    slug: { strategy: 'charset', length: 8, alphabet: LOWER + DIGITS },
};

/**
 * Resolve the effective config: preset base + legacy kind + explicit overrides.
 *
 * Only knobs that carry a value override: `null`/`undefined` mean "not set",
 * so a serialized `strategy: null` or `alphabet: null` never wipes out what
 * the preset (or the legacy kind) defines.
 */
export function resolveGeneratorConfig(
    input: KinetixGeneratorConfig,
): KinetixGeneratorConfig {
    const config = Object.fromEntries(
        Object.entries(input).filter(
            ([, value]) => value !== null && value !== undefined,
        ),
    ) as KinetixGeneratorConfig;
    const base = config.preset ? (GENERATOR_PRESETS[config.preset] ?? {}) : {};

    // Legacy kinds map onto a strategy so old configs keep working untouched.
    let legacy: KinetixGeneratorConfig = {};

    if (!config.preset && !config.strategy) {
        if (config.kind === 'pin') {
            legacy = { strategy: 'charset' };
        } else if (config.kind === 'username') {
            legacy = {
                strategy: config.pattern ? 'template' : 'charset',
                alphabet: config.pattern ? null : LOWER + DIGITS,
            };
        } else {
            legacy = { strategy: 'charset' };
        }
    }

    // Explicit config wins over the preset; preset wins over legacy defaults.
    return { ...legacy, ...base, ...config };
}

export function useKinetixGenerator(getConfig: () => KinetixGeneratorConfig) {
    const generate = (values: Record<string, unknown> = {}): string => {
        const config = resolveGeneratorConfig(getConfig() ?? {});
        const length = Math.max(1, config.length ?? 16);
        const prefix = config.prefix ?? '';

        switch (config.strategy) {
            case 'uuid':
                return prefix + uuidV4();

            case 'mask':
                return prefix + fromMask(config.mask || '****-****');

            case 'words':
                return prefix + memorableWords(config);

            case 'template': {
                if (config.pattern) {
                    const fromPattern = usernameFromPattern(
                        config.pattern,
                        values,
                        config.separator || '.',
                    );

                    if (fromPattern) {
                        return prefix + fromPattern;
                    }
                }

                // Fall back to a random handle when the pattern is empty.
                return prefix + randomString(LOWER + DIGITS, length);
            }

            case 'charset':
            default:
                return prefix + charsetValue(config, length);
        }
    };

    return { generate, normalizeHandle, presets: GENERATOR_PRESETS };
}
