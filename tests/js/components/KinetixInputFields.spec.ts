import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixRating from '@/components/KinetixRating.vue';
import KinetixPinInput from '@/components/KinetixPinInput.vue';
import KinetixSlider from '@/components/KinetixSlider.vue';

describe('KinetixSlider', () => {
    it('shows the current value', () => {
        const w = mount(KinetixSlider, {
            props: { value: 42, config: { min: 0, max: 100, step: 1 } },
        });
        expect(w.text()).toContain('42');
    });
});

describe('KinetixRating', () => {
    it('renders one button per star', () => {
        const w = mount(KinetixRating, {
            props: { value: 3, config: { max: 5 } },
        });
        expect(w.findAll('button').length).toBe(5);
    });

    it('emits the clicked rating, and 0 when clicking the current value', async () => {
        const w = mount(KinetixRating, {
            props: { value: 0, config: { max: 5 } },
        });
        const stars = w.findAll('button');
        await stars[2].trigger('click'); // 3rd star
        let events = w.emitted('update:value');
        expect(events![0][0]).toBe(3);

        // Re-click the current value clears it.
        await w.setProps({ value: 3 });
        await stars[2].trigger('click');
        events = w.emitted('update:value');
        expect(events![events!.length - 1][0]).toBe(0);
    });
});

describe('KinetixPinInput', () => {
    // Reka adds a binding input for form submission; the segments carry inputmode.
    const boxes = (w: ReturnType<typeof mount>) =>
        w.findAll<HTMLInputElement>('input[inputmode]');

    it('seeds the leading boxes from the value and puts the field id on the first', () => {
        const w = mount(KinetixPinInput, {
            props: { id: 'code', value: '12', config: { length: 4 } },
        });

        const inputs = boxes(w);
        expect(inputs.map((el) => el.element.value)).toEqual([
            '1',
            '2',
            '',
            '',
        ]);
        expect(inputs[0].attributes('id')).toBe('code');
        expect(inputs[1].attributes('id')).toBeUndefined();
    });

    it('emits the joined value when a box is filled', async () => {
        const w = mount(KinetixPinInput, {
            props: { value: '12', config: { length: 4 } },
        });

        await boxes(w)[2].setValue('3');

        expect(w.emitted('update:value')?.at(-1)).toEqual(['123']);
    });
});
