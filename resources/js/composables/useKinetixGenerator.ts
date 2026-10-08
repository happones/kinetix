/**
 * The value-generation engine shared by the `<KinetixGenerator>` standalone
 * component and the `generator-input` form field. Pure logic — no DOM, no Vue —
 * so it's trivially testable and reusable.
 *
 * Randomness comes from `crypto.getRandomValues` with REJECTION SAMPLING, so
 * the character distribution is uniform (no modulo bias). Three kinds:
 *   - password: a mixed-class random string (lower/upper/digits/symbols);
 *   - pin: a fixed-length code (numeric / alphanum / alpha);
 *   - username: a pattern of `{field}` tokens filled from sibling values and
 *     normalized to a safe handle, or a random handle when there's no pattern.
 */

export interface KinetixGeneratorConfig {
    kind?: 'password' | 'pin' | 'username';
    length?: number;
    lowercase?: boolean;
    uppercase?: boolean;
    digits?: boolean;
    symbols?: boolean;
    symbolSet?: string | null;
    excludeAmbiguous?: boolean;
    pinMode?: 'alpha' | 'alphanum' | 'numeric';
    pattern?: string | null;
    separator?: string | null;
    copyable?: boolean;
    revealable?: boolean;
}

const LOWER = 'abcdefghijklmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const DIGITS = '0123456789';
const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?';
const AMBIGUOUS = /[O0oIl1|]/g;

/** A uniformly-random integer in [0, max) via rejection sampling. */
function randomInt(max: number): number {
    if (max <= 0) {
        return 0;
    }

    const limit = Math.floor(0xffffffff / max) * max;
    const buf = new Uint32Array(1);

    // Reject values in the final, partial bucket so each outcome is equally
    // likely (plain `% max` would bias the low values).
    let n = 0;

    do {
        crypto.getRandomValues(buf);
        n = buf[0];
    } while (n >= limit);

    return n % max;
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

function passwordAlphabet(config: KinetixGeneratorConfig): string {
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

    // Fall back to lowercase+digits if every class was switched off.
    if (!alphabet) {
        alphabet = LOWER + DIGITS;
    }

    if (config.excludeAmbiguous) {
        alphabet = alphabet.replace(AMBIGUOUS, '');
    }

    return alphabet;
}

function pinAlphabet(mode: KinetixGeneratorConfig['pinMode']): string {
    switch (mode) {
        case 'alpha':
            return LOWER + UPPER;
        case 'alphanum':
            return LOWER + UPPER + DIGITS;
        default:
            return DIGITS;
    }
}

/** Normalize arbitrary text into a safe username/handle. */
export function normalizeHandle(text: string, separator = '.'): string {
    const sep = separator || '.';
    const escaped = sep.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    return (text ?? '')
        .toString()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '') // strip accents
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, sep)
        .replace(new RegExp(`${escaped}+`, 'g'), sep)
        .replace(new RegExp(`^${escaped}|${escaped}$`, 'g'), '');
}

/**
 * Build a username from a `{field}` pattern and the form's sibling values.
 * Tokens with no matching value drop out; the whole result is normalized.
 */
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

export function useKinetixGenerator(getConfig: () => KinetixGeneratorConfig) {
    const generate = (values: Record<string, unknown> = {}): string => {
        const config = getConfig() ?? {};
        const length = Math.max(1, config.length ?? 16);

        if (config.kind === 'pin') {
            return randomString(pinAlphabet(config.pinMode), length);
        }

        if (config.kind === 'username') {
            if (config.pattern) {
                const fromPattern = usernameFromPattern(
                    config.pattern,
                    values,
                    config.separator || '.',
                );

                // Fall back to a random handle if the pattern resolved empty
                // (siblings not filled in yet).
                if (fromPattern) {
                    return fromPattern;
                }
            }

            return randomString(LOWER + DIGITS, length);
        }

        // Default: password.
        return randomString(passwordAlphabet(config), length);
    };

    return { generate, normalizeHandle };
}
