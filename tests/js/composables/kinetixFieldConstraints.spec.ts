import { describe, expect, it } from 'vitest';
import {
    applyConstraintCase,
    characterCounter,
    confirmsMatch,
    constraintInputAttributes,
    dateBounds,
    fieldRequirements,
    fileAccept,
    fileProblem,
    formatHolds,
    passwordStrength,
    requirementHints,
    requirementViolation,
} from '@/composables/kinetixFieldConstraints';
import type { KinetixFieldConstraints } from '@/composables/kinetixFieldConstraints';

// Keys with their params, so each assertion names the requirement it meant.
const t = (key: string, params: Record<string, unknown> = {}): string => {
    const name = key.replace('kinetix.', '');
    const args = Object.entries(params)
        .map(([k, v]) => `${k}=${v}`)
        .join(',');

    return args ? `${name}(${args})` : name;
};
const labelOf = (field: string) => `[${field}]`;

const hintsOf = (c: KinetixFieldConstraints, counter = false) =>
    requirementHints(fieldRequirements(c, t, labelOf, { counter }));

const violation = (
    c: KinetixFieldConstraints,
    value: unknown,
    values: Record<string, unknown> = {},
) => requirementViolation(fieldRequirements(c, t, labelOf), value, values, t);

describe('fieldRequirements', () => {
    it('says what a string length asks, unless a counter shows it', () => {
        expect(hintsOf({ kind: 'string', length: { max: 120 } })).toEqual([
            'rule_length_max(max=120)',
        ]);
        expect(hintsOf({ kind: 'string', length: { max: 120 } }, true)).toEqual(
            [],
        );
        expect(
            hintsOf({ kind: 'string', length: { min: 3, max: 120 } }, true),
        ).toEqual(['rule_length_between(min=3,max=120)']);
        // Characters, not UTF-16 units.
        expect(
            violation({ kind: 'string', length: { max: 3 } }, '🙂🙂🙂'),
        ).toBe(null);
        expect(violation({ kind: 'string', length: { min: 3 } }, 'ab')).toBe(
            'rule_length_min(min=3)',
        );
    });

    it('checks numbers against their range, step and decimals', () => {
        const c: KinetixFieldConstraints = {
            kind: 'numeric',
            range: { min: 1, max: 10 },
            step: 0.5,
            decimals: { min: 0, max: 1 },
        };

        expect(hintsOf(c)).toEqual([
            'rule_number_between(min=1,max=10)',
            'rule_step(step=0.5)',
            'rule_decimals_between(min=0,max=1)',
        ]);
        expect(violation(c, '11')).toBe('rule_number_between(min=1,max=10)');
        expect(violation(c, '2.3')).toBe('rule_step(step=0.5)');
        expect(violation(c, '2.5')).toBe(null);
        expect(violation({ kind: 'numeric', range: { gt: 0 } }, '0')).toBe(
            'rule_number_gt(value=0)',
        );
        expect(violation({ kind: 'numeric', integer: true }, '1.5')).toBe(
            'rule_integer',
        );
    });

    it('checks digits, character sets, case and affixes', () => {
        expect(
            violation({ kind: 'string', digits: { min: 6, max: 6 } }, '12a456'),
        ).toBe('rule_digits_exact(digits=6)');
        expect(
            violation({ kind: 'string', charset: 'alpha_dash' }, 'my-slug_2'),
        ).toBe(null);
        expect(
            violation({ kind: 'string', charset: 'alpha_dash' }, 'my slug'),
        ).toBe('rule_charset_alpha_dash');
        expect(violation({ kind: 'string', charset: 'alpha' }, 'Ñandú')).toBe(
            null,
        );
        expect(violation({ kind: 'string', case: 'lower' }, 'Abc')).toBe(
            'rule_lowercase',
        );
        expect(
            hintsOf({ kind: 'string', startsWith: ['INV-', 'PO-'] }),
        ).toEqual(['rule_starts_with(values="INV-"rule_or"PO-")']);
        expect(
            violation({ kind: 'string', startsWith: ['INV-'] }, 'PO-1'),
        ).not.toBe(null);
        expect(
            violation({ kind: 'string', notIn: ['admin'] }, 'admin'),
        ).not.toBe(null);
    });

    it('checks formats, with no hint where the input says it', () => {
        expect(hintsOf({ kind: 'string', format: 'email' })).toEqual([]);
        expect(violation({ kind: 'string', format: 'email' }, 'ada@')).toBe(
            'rule_pattern',
        );
        expect(
            violation({ kind: 'string', format: 'ipv4' }, '192.168.0.1'),
        ).toBe(null);
        expect(violation({ kind: 'string', format: 'ipv4' }, '256.1.1.1')).toBe(
            'rule_format_ipv4',
        );
        expect(
            violation({ kind: 'string', format: 'ipv6' }, '2001:db8::1'),
        ).toBe(null);
        expect(
            violation({ kind: 'string', format: 'mac' }, '00-1A-2B-3C-4D-5E'),
        ).toBe(null);
        expect(
            violation(
                { kind: 'string', format: 'uuid' },
                '123e4567-e89b-12d3-a456-426614174000',
            ),
        ).toBe(null);
        expect(
            violation({ kind: 'string', format: 'hex_color' }, '#3B82F6'),
        ).toBe(null);
        expect(violation({ kind: 'string', format: 'json' }, '{"a":')).toBe(
            'rule_format_json',
        );
        expect(
            violation(
                { kind: 'string', format: 'timezone' },
                'America/Mexico_City',
            ),
        ).toBe(null);
        expect(
            violation(
                {
                    kind: 'string',
                    pattern: { source: '^[A-Z]{3}$', flags: '' },
                },
                'ab',
            ),
        ).toBe('rule_pattern');
    });

    it('compares with other fields, once they have a value', () => {
        const c: KinetixFieldConstraints = {
            kind: 'numeric',
            compare: [{ op: 'gte', field: 'min_price' }],
        };

        expect(hintsOf(c)).toEqual(['rule_compare_gte(field=[min_price])']);
        expect(violation(c, '5', { min_price: '10' })).toBe(
            'rule_compare_gte(field=[min_price])',
        );
        expect(violation(c, '15', { min_price: '10' })).toBe(null);
        expect(violation(c, '5', { min_price: '' })).toBe(null);
    });

    it('confirms a field that must match another', () => {
        const c: KinetixFieldConstraints = {
            kind: 'string',
            compare: [{ op: 'same', field: 'password' }],
        };
        const requirements = fieldRequirements(c, t, labelOf);

        expect(
            confirmsMatch(requirements, 'secret1', { password: 'secret1' }),
        ).toBe(true);
        expect(
            confirmsMatch(requirements, 'secret', { password: 'secret1' }),
        ).toBe(false);
        expect(violation(c, 'secret', { password: 'secret1' })).toBe(
            'rule_same(field=[password])',
        );
    });

    it('checks dates against fixed dates and other fields', () => {
        const c: KinetixFieldConstraints = {
            kind: 'string',
            dates: [
                { op: 'after_or_equal', date: '2026-01-01' },
                { op: 'before', field: 'ends_on' },
            ],
        };

        expect(violation(c, '2025-12-31')).not.toBe(null);
        expect(violation(c, '2026-03-01', { ends_on: '2026-02-01' })).toBe(
            'rule_date_before(date=[ends_on])',
        );
        expect(violation(c, '2026-01-15', { ends_on: '2026-02-01' })).toBe(
            null,
        );
        expect(
            hintsOf({
                kind: 'string',
                dates: [{ op: 'after', date: 'today' }],
            }),
        ).toEqual(['rule_date_after(date=rule_date_today)']);
    });

    it('lists a password policy and checks each part', () => {
        const c: KinetixFieldConstraints = {
            kind: 'string',
            password: {
                min: 10,
                letters: true,
                mixedCase: true,
                numbers: true,
                symbols: true,
                uncompromised: true,
            },
        };

        expect(hintsOf(c)).toEqual([
            'rule_password_min(min=10)',
            'rule_password_mixed_case',
            'rule_password_numbers',
            'rule_password_symbols',
            'rule_password_uncompromised',
        ]);
        expect(violation(c, 'Secret-pass1')).toBe(null);
        expect(violation(c, 'secret-pass1')).toBe('rule_password_mixed_case');
    });

    it('summarises what a file must be', () => {
        expect(
            hintsOf({
                kind: 'file',
                fileTypes: ['png', 'jpg'],
                kilobytes: { max: 2048 },
                dimensions: { minWidth: 800, minHeight: 600 },
            }),
        ).toEqual([
            'PNG, JPG · rule_file_max(size=2 MB) · rule_file_min_dimensions(width=800,height=600)',
        ]);
    });

    it('leaves an empty value to the server', () => {
        expect(violation({ kind: 'string', length: { min: 3 } }, '')).toBe(
            null,
        );
    });
});

describe('field helpers', () => {
    it('counts characters against the maximum', () => {
        expect(
            characterCounter({ kind: 'string', length: { max: 5 } }, 'héllo!'),
        ).toEqual({ count: 6, max: 5 });
        expect(
            characterCounter({ kind: 'numeric', range: { max: 5 } }, '3'),
        ).toBe(null);
    });

    it('picks the keyboard and turns off autocorrect on codes', () => {
        expect(
            constraintInputAttributes({ kind: 'numeric', integer: true }),
        ).toEqual({ inputmode: 'numeric' });
        expect(
            constraintInputAttributes({
                kind: 'numeric',
                decimals: { min: 0, max: 2 },
            }),
        ).toEqual({ inputmode: 'decimal' });
        expect(
            constraintInputAttributes({ kind: 'string', case: 'upper' }),
        ).toEqual({
            autocapitalize: 'characters',
            autocorrect: 'off',
            spellcheck: 'false',
        });
        expect(
            constraintInputAttributes({ kind: 'string', format: 'email' }),
        ).toEqual({
            inputmode: 'email',
            autocapitalize: 'none',
            autocorrect: 'off',
            spellcheck: 'false',
        });
    });

    it('applies the case the rules ask for', () => {
        expect(
            applyConstraintCase({ kind: 'string', case: 'upper' }, 'abc'),
        ).toBe('ABC');
        expect(applyConstraintCase(null, 'Abc')).toBe('Abc');
    });
});

describe('dedicated input helpers', () => {
    it('rates a password from empty to strong', () => {
        expect(passwordStrength('')).toBe(0);
        expect(passwordStrength('aaaaaaaa')).toBe(1);
        expect(passwordStrength('password123')).toBeLessThan(3);
        expect(passwordStrength('Correct-Horse-9-Battery')).toBe(4);
    });

    it('bounds a date picker by fixed dates and other fields', () => {
        expect(
            dateBounds(
                {
                    kind: 'string',
                    dates: [
                        { op: 'after', date: '2026-01-10' },
                        { op: 'before_or_equal', field: 'ends_on' },
                    ],
                },
                { ends_on: '2026-02-01' },
            ),
        ).toEqual({ min: '2026-01-11', max: '2026-02-01' });

        // The tightest bound wins, a fixed one included.
        expect(
            dateBounds(
                {
                    kind: 'string',
                    dates: [{ op: 'after_or_equal', date: '2026-01-01' }],
                },
                {},
                { min: '2026-03-01' },
            ),
        ).toEqual({ min: '2026-03-01', max: null });

        // A field not filled yet doesn't bound anything.
        expect(
            dateBounds(
                { kind: 'string', dates: [{ op: 'before', field: 'ends_on' }] },
                {},
            ),
        ).toEqual({ min: null, max: null });

        expect(
            dateBounds(
                {
                    kind: 'string',
                    dates: [{ op: 'after', date: '2026-01-10' }],
                },
                {},
                {},
                true,
            ).min,
        ).toBe('2026-01-10T00:01');
    });

    it('refuses a file of the wrong type or size before it is sent', async () => {
        const c: KinetixFieldConstraints = {
            kind: 'file',
            fileTypes: ['jpg', 'png'],
            kilobytes: { max: 1 },
        };
        const file = (name: string, bytes: number, type = '') =>
            new File(['x'.repeat(bytes)], name, { type });

        expect(await fileProblem(file('cv.pdf', 10), c, t)).toBe(
            'upload_rejected_type(file=cv.pdf,types=JPG, PNG)',
        );
        // jpg and jpeg are one type, as the server reads them.
        expect(await fileProblem(file('me.jpeg', 10), c, t)).toBe(null);
        expect(await fileProblem(file('me.png', 4096), c, t)).toBe(
            'upload_rejected_size(file=me.png,size=4 KB,max=1 KB)',
        );
        expect(
            await fileProblem(
                file('notes.txt', 10, 'text/plain'),
                { kind: 'file', image: true },
                t,
            ),
        ).toBe('upload_rejected_type(file=notes.txt,types=rule_file_image)');
        expect(fileAccept(c)).toBe('.jpg,.png,.jpeg');
    });

    it('tells whether a code has its format', () => {
        expect(
            formatHolds('uuid', '123e4567-e89b-12d3-a456-426614174000'),
        ).toBe(true);
        expect(formatHolds('uuid', '123')).toBe(false);
        expect(formatHolds('uuid', '')).toBe(null);
        expect(formatHolds(undefined, 'x')).toBe(null);
    });

    it('leaves the policy out of the hints when the input lists it', () => {
        const requirements = fieldRequirements(
            {
                kind: 'string',
                length: { min: 2 },
                password: {
                    min: 8,
                    letters: false,
                    mixedCase: false,
                    numbers: true,
                    symbols: false,
                    uncompromised: false,
                },
            },
            t,
            labelOf,
        );

        expect(requirementHints(requirements, ['password'])).toEqual([
            'rule_length_min(min=2)',
        ]);
    });
});
