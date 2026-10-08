import { describe, expect, it } from 'vitest';
import {
    useKinetixGenerator,
    normalizeHandle,
    GENERATOR_PRESETS,
} from '@/composables/useKinetixGenerator';
import type { KinetixGeneratorConfig } from '@/composables/useKinetixGenerator';
import contract from '../fixtures/generator-configs.json';

describe('useKinetixGenerator', () => {
    it('generates a password of the requested length from the enabled classes', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'password',
            length: 20,
            lowercase: true,
            uppercase: false,
            digits: true,
            symbols: false,
        }));

        const pw = generate();
        expect(pw).toHaveLength(20);
        // Only lowercase + digits were enabled.
        expect(pw).toMatch(/^[a-z0-9]+$/);
    });

    it('honours a numeric PIN mode and length', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'pin',
            length: 6,
            pinMode: 'numeric',
        }));

        const pin = generate();
        expect(pin).toHaveLength(6);
        expect(pin).toMatch(/^[0-9]{6}$/);
    });

    it('alphanum PIN uses letters and digits', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'pin',
            length: 8,
            pinMode: 'alphanum',
        }));

        expect(generate()).toMatch(/^[a-zA-Z0-9]{8}$/);
    });

    it('builds a username from a pattern of sibling fields', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'username',
            pattern: '{first}.{last}',
            separator: '.',
        }));

        expect(generate({ first: 'Ada', last: 'Lovelace' })).toBe(
            'ada.lovelace',
        );
        // Accents stripped, spaces collapsed to the separator.
        expect(generate({ first: 'José María', last: 'Núñez' })).toBe(
            'jose.maria.nunez',
        );
    });

    it('falls back to a random handle when the pattern resolves empty', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'username',
            length: 10,
            pattern: '{first}.{last}',
        }));

        const handle = generate({}); // no sibling values yet
        expect(handle).toHaveLength(10);
        expect(handle).toMatch(/^[a-z0-9]+$/);
    });

    it('normalizeHandle lowercases, strips accents and collapses separators', () => {
        expect(normalizeHandle('  Hello  World!! ', '-')).toBe('hello-world');
        expect(normalizeHandle('Àéîõü', '.')).toBe('aeiou');
    });

    it('excludeAmbiguous drops look-alike characters', () => {
        const { generate } = useKinetixGenerator(() => ({
            kind: 'password',
            length: 200,
            lowercase: true,
            uppercase: true,
            digits: true,
            symbols: false,
            excludeAmbiguous: true,
        }));

        expect(generate()).not.toMatch(/[O0oIl1|]/);
    });
});

describe('useKinetixGenerator — presets & strategies', () => {
    const gen = (config: Record<string, unknown>) =>
        useKinetixGenerator(() => config).generate();

    it('uuid preset produces an RFC-4122 v4 UUID', () => {
        const uuid = gen({ preset: 'uuid' });
        expect(uuid).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
    });

    it('license-key preset fills the mask with grouped alphanumerics', () => {
        const key = gen({ preset: 'license-key' });
        expect(key).toMatch(
            /^[0-9A-Za-z]{4}-[0-9A-Za-z]{4}-[0-9A-Za-z]{4}-[0-9A-Za-z]{4}$/,
        );
    });

    it('api-key preset carries its prefix and base62 body', () => {
        const key = gen({ preset: 'api-key' });
        expect(key.startsWith('sk_')).toBe(true);
        expect(key.slice(3)).toMatch(/^[0-9A-Za-z]{40}$/);
    });

    it('memorable preset is adjective-noun words with trailing digits', () => {
        const pw = gen({ preset: 'password-memorable' });
        expect(pw).toMatch(/^[a-z]+-[a-z]+-[a-z]+-\d{2}$/);
    });

    it('hex preset is lowercase hex of the right length', () => {
        expect(gen({ preset: 'hex' })).toMatch(/^[0-9a-f]{32}$/);
    });

    it('otp preset is 6 digits', () => {
        expect(gen({ preset: 'otp' })).toMatch(/^\d{6}$/);
    });

    it('a custom alphabet (charset strategy) uses only those chars', () => {
        const v = gen({ strategy: 'charset', alphabet: 'XYZ', length: 30 });
        expect(v).toMatch(/^[XYZ]{30}$/);
    });

    it('a custom mask keeps literals and fills tokens', () => {
        const v = gen({ strategy: 'mask', mask: 'INV-####-AA' });
        expect(v).toMatch(/^INV-\d{4}-[A-Z]{2}$/);
    });

    it('an explicit override wins over the preset (length)', () => {
        const v = gen({ preset: 'hex', length: 8 });
        expect(v).toMatch(/^[0-9a-f]{8}$/);
    });

    it('GENERATOR_PRESETS is a non-empty catalog', () => {
        expect(Object.keys(GENERATOR_PRESETS).length).toBeGreaterThan(10);
    });
});

/**
 * The PHP field and this engine share one contract: tests/js/fixtures/
 * generator-configs.json holds what GeneratorInput::toData() really emits
 * (GeneratorInputTest asserts that side). Generating from those exact
 * payloads catches a mismatch neither side's own tests can see — hand-built
 * configs here hid that every PHP preset generated 16 digits.
 */
describe('useKinetixGenerator — contract with GeneratorInput (PHP)', () => {
    const SAMPLES = 200;
    const sample = (name: string, values: Record<string, unknown> = {}) => {
        const { generate } = useKinetixGenerator(
            () => contract[name] as KinetixGeneratorConfig,
        );

        return Array.from({ length: SAMPLES }, () => generate(values));
    };
    const union = (values: string[]) => values.join('');

    const expectations: Record<string, (values: string[]) => void> = {
        'legacy-default': (v) => {
            v.forEach((s) => expect(s).toHaveLength(16));
            expect(union(v)).toMatch(/[a-z]/);
            expect(union(v)).toMatch(/[A-Z]/);
            expect(union(v)).toMatch(/[^A-Za-z0-9]/);
        },
        'legacy-password-24-no-symbols': (v) => {
            v.forEach((s) => expect(s).toMatch(/^[A-Za-z0-9]{24}$/));
            expect(union(v)).toMatch(/[a-z]/);
        },
        'legacy-pin-alphanum': (v) => {
            v.forEach((s) => expect(s).toMatch(/^[A-Za-z0-9]{4}$/));
            expect(union(v)).toMatch(/[A-Za-z]/);
        },
        'legacy-pin-numeric': (v) =>
            v.forEach((s) => expect(s).toMatch(/^\d{6}$/)),
        'legacy-username-pattern': (v) =>
            v.forEach((s) => expect(s).toBe('ada.lovelace')),
        'legacy-username-random': (v) =>
            v.forEach((s) => expect(s).toMatch(/^[a-z0-9]{10}$/)),
        'preset-uuid': (v) =>
            v.forEach((s) =>
                expect(s).toMatch(
                    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
                ),
            ),
        'preset-api-key': (v) =>
            v.forEach((s) => expect(s).toMatch(/^sk_[A-Za-z0-9]{40}$/)),
        'preset-license-key': (v) =>
            v.forEach((s) =>
                expect(s).toMatch(/^[A-Za-z0-9]{4}(-[A-Za-z0-9]{4}){3}$/),
            ),
        'preset-password-simple': (v) =>
            v.forEach((s) => {
                expect(s).toMatch(/^[A-Za-z0-9]{14}$/);
                expect(s).not.toMatch(/[O0oIl1]/);
            }),
        'preset-password-strong-24': (v) => {
            v.forEach((s) => expect(s).toHaveLength(24));
            expect(union(v)).toMatch(/[^A-Za-z0-9]/);
        },
        'preset-passphrase': (v) =>
            v.forEach((s) => expect(s).toMatch(/^[a-z]+( [a-z]+){3}$/)),
        'custom-alphabet': (v) =>
            v.forEach((s) => expect(s).toMatch(/^[ABC123]{8}$/)),
        'custom-mask': (v) =>
            v.forEach((s) => expect(s).toMatch(/^INV-\d{4}-[A-Z]{2}$/)),
        words: (v) =>
            v.forEach((s) => expect(s).toMatch(/^[a-z]+_[a-z]+_\d{3}$/)),
    };

    it('has an expectation for every fixture entry', () => {
        expect(Object.keys(expectations).sort()).toEqual(
            Object.keys(contract).sort(),
        );
    });

    it.each(Object.keys(expectations))(
        'generates the right shape from the PHP payload: %s',
        (name) => {
            expectations[name](
                sample(name, { first: 'Ada', last: 'Lovelace' }),
            );
        },
    );

    it('never lets a null knob override the preset', () => {
        const { generate } = useKinetixGenerator(() => ({
            preset: 'uuid',
            strategy: null as never,
            alphabet: null,
            mask: null,
        }));

        expect(generate()).toMatch(/^[0-9a-f]{8}-/);
    });
});
