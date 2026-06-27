import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixCheckbox from '@/components/KinetixCheckbox.vue';

describe('KinetixCheckbox', () => {
    it('reflects the checked prop via aria-checked', () => {
        const wrapper = mount(KinetixCheckbox, { props: { checked: true } });

        expect(
            wrapper.get('[role="checkbox"]').attributes('aria-checked'),
        ).toBe('true');
    });

    it('emits update:modelValue and change with the toggled value on click', async () => {
        const wrapper = mount(KinetixCheckbox, {
            props: { modelValue: false },
        });

        await wrapper.get('[role="checkbox"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true]);
        expect(wrapper.emitted('change')?.[0]).toEqual([true]);
    });

    it('does not emit when disabled', async () => {
        const wrapper = mount(KinetixCheckbox, {
            props: { modelValue: false, disabled: true },
        });

        await wrapper.get('[role="checkbox"]').trigger('click');

        expect(wrapper.emitted('change')).toBeUndefined();
    });

    it('prefers the checked prop over modelValue', () => {
        const wrapper = mount(KinetixCheckbox, {
            props: { checked: false, modelValue: true },
        });

        expect(
            wrapper.get('[role="checkbox"]').attributes('aria-checked'),
        ).toBe('false');
    });
});
