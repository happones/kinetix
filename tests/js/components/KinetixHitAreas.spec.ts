import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixCheckbox from '@/components/KinetixCheckbox.vue';
import KinetixSwitch from '@/components/primitives/KinetixSwitch.vue';

/**
 * The boolean controls are drawn smaller than WCAG 2.5.8's 24×24px minimum
 * (a 16px checkbox, an 18px-tall switch); an invisible absolutely positioned
 * ::after extends what a tap reaches without changing how they look. It hangs
 * off the padding box, 1px inside the border, so it reaches 5px out: 4px left
 * the checkbox at 22px.
 */
describe('boolean control hit areas', () => {
    it('extends the switch vertically to 26px', () => {
        const root = mount(KinetixSwitch).get('button[role="switch"]');

        expect(root.classes()).toEqual(
            expect.arrayContaining([
                'relative',
                'after:absolute',
                'after:inset-x-0',
                'after:-inset-y-[5px]',
            ]),
        );
    });

    it('extends the checkbox to 24px on every side', () => {
        const root = mount(KinetixCheckbox).get('button[role="checkbox"]');

        expect(root.classes()).toEqual(
            expect.arrayContaining([
                'relative',
                'after:absolute',
                'after:-inset-[5px]',
            ]),
        );
    });
});
