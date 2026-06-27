import { afterEach, describe, expect, it } from 'vitest';
import { applyKinetixAccessibility } from '@/composables/useKinetixAccessibility';

describe('applyKinetixAccessibility', () => {
    afterEach(() => {
        document.documentElement.className = '';
    });

    it('toggles preference classes on <html>', () => {
        applyKinetixAccessibility({
            reducedMotion: true,
            highContrast: true,
            underlineLinks: true,
            enhancedFocus: true,
            textSize: 'normal',
        });
        const c = document.documentElement.classList;
        expect(c.contains('kx-reduce-motion')).toBe(true);
        expect(c.contains('kx-high-contrast')).toBe(true);
        expect(c.contains('kx-underline-links')).toBe(true);
        expect(c.contains('kx-enhanced-focus')).toBe(true);
    });

    it('maps text size to the right class and is exclusive', () => {
        applyKinetixAccessibility({ textSize: 'large' });
        expect(
            document.documentElement.classList.contains('kx-text-large'),
        ).toBe(true);

        applyKinetixAccessibility({ textSize: 'x-large' });
        const c = document.documentElement.classList;
        expect(c.contains('kx-text-large')).toBe(false);
        expect(c.contains('kx-text-x-large')).toBe(true);

        applyKinetixAccessibility({ textSize: 'normal' });
        expect(
            document.documentElement.classList.contains('kx-text-x-large'),
        ).toBe(false);
    });

    it('removes classes when preferences are turned off', () => {
        applyKinetixAccessibility({ reducedMotion: true });
        applyKinetixAccessibility({ reducedMotion: false });
        expect(
            document.documentElement.classList.contains('kx-reduce-motion'),
        ).toBe(false);
    });
});
