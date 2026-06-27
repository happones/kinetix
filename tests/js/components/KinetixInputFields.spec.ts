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
    it('renders one box per length and seeds from the value', () => {
        const w = mount(KinetixPinInput, {
            props: { value: '12', config: { length: 4 } },
        });
        // Reka adds a binding input for form submission; the 4 segments carry inputmode.
        expect(w.findAll('input[inputmode]').length).toBe(4);
    });
});
