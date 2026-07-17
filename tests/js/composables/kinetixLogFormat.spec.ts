import { describe, expect, it } from 'vitest';
import {
    formatTime,
    pretty,
    statusClass,
} from '@/composables/kinetixLogFormat';

describe('kinetixLogFormat', () => {
    it('pretty-prints JSON strings and objects, dashing empties', () => {
        expect(pretty('{"a":1}')).toBe('{\n  "a": 1\n}');
        expect(pretty({ b: 2 })).toBe('{\n  "b": 2\n}');
        expect(pretty('plain text')).toBe('plain text');
        expect(pretty('')).toBe('—');
        expect(pretty(null)).toBe('—');
        expect(pretty(undefined)).toBe('—');
    });

    it('picks success vs failure badge classes', () => {
        expect(statusClass(true)).toContain('text-success');
        expect(statusClass(false)).toContain('text-destructive');
    });

    it('formats a timestamp or dashes a null', () => {
        expect(formatTime(null)).toBe('—');
        expect(formatTime('2026-01-01T00:00:00Z')).not.toBe('—');
    });
});
