import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixRadioGroup from '@/components/KinetixRadioGroup.vue';
import KinetixSelect from '@/components/KinetixSelect.vue';

describe('KinetixSelect', () => {
    it('renders a combobox trigger', () => {
        const wrapper = mount(KinetixSelect, {
            props: {
                value: null,
                options: { a: 'Option A', b: 'Option B' },
                placeholder: 'Pick one',
            },
        });

        expect(wrapper.get('[role="combobox"]')).toBeTruthy();
    });
});

describe('KinetixRadioGroup', () => {
    it('renders one radio per option', () => {
        const wrapper = mount(KinetixRadioGroup, {
            props: { value: null, options: { a: 'A', b: 'B', c: 'C' } },
        });

        expect(wrapper.findAll('[role="radio"]')).toHaveLength(3);
    });

    it('emits update:value when an option is selected', async () => {
        const wrapper = mount(KinetixRadioGroup, {
            props: { value: null, options: { draft: 'Draft', live: 'Live' } },
        });

        await wrapper.findAll('[role="radio"]')[1].trigger('click');

        expect(wrapper.emitted('update:value')?.[0]).toEqual(['live']);
    });
});
