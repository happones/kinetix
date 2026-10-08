import { describe, expect, it } from 'vitest';
import {
    useKinetixGenerator,
    normalizeHandle,
} from '@/composables/useKinetixGenerator';

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
