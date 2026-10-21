import { flushPromises, mount } from '@vue/test-utils';
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

    async function openOptions(
        props: Record<string, unknown>,
    ): Promise<string[]> {
        const wrapper = mount(KinetixSelect, {
            props,
            attachTo: document.body,
        });
        await wrapper
            .get('[role="combobox"]')
            .trigger('pointerdown', { button: 0, pointerType: 'mouse' });
        await flushPromises();

        const labels = [...document.querySelectorAll('[role="option"]')].map(
            (option) => option.textContent?.trim() ?? '',
        );
        wrapper.unmount();
        document.body.innerHTML = '';

        return labels;
    }

    it('keeps an empty choice on top of id keys', async () => {
        expect(
            await openOptions({
                value: '',
                options: { 7: 'Ventas', 3: 'Soporte', '': 'All lines' },
            }),
        ).toEqual(['All lines', 'Soporte', 'Ventas']);
    });

    it('lists array options in their order', async () => {
        expect(
            await openOptions({
                value: '',
                options: [
                    { value: '', label: 'All lines' },
                    { value: 7, label: 'Ventas' },
                    { value: 3, label: 'Soporte' },
                ],
            }),
        ).toEqual(['All lines', 'Ventas', 'Soporte']);
    });

    it('shows the label of a numeric value and comes in a small size', async () => {
        const wrapper = mount(KinetixSelect, {
            props: {
                value: 7,
                options: [{ value: 7, label: 'Ventas' }],
                size: 'sm',
            },
            attachTo: document.body,
        });
        await flushPromises();
        const trigger = wrapper.get('[role="combobox"]');

        expect(trigger.text()).toBe('Ventas');
        expect(trigger.classes()).toContain('h-8');
        wrapper.unmount();
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
