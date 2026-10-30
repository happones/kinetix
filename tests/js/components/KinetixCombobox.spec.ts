import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: vi.fn(),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixCombobox from '@/components/KinetixCombobox.vue';
import { i18n } from './i18n';

const OPTIONS = { draft: 'Draft', active: 'Active' };

/** The field as a form renders it: a <label for> pointing at the control. */
const Field = defineComponent({
    props: { ariaLabel: { type: String, default: undefined } },
    setup(props) {
        return () =>
            h('div', [
                h('label', { for: 'status' }, 'Status'),
                h(KinetixCombobox, {
                    id: 'status',
                    options: OPTIONS,
                    ...(props.ariaLabel
                        ? { 'aria-label': props.ariaLabel }
                        : {}),
                }),
            ]);
    },
});

describe('KinetixCombobox', () => {
    it('is reached by Tab and named by its field label, not "Show popup"', () => {
        const wrapper = mount(Field, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        const trigger = wrapper.get('#status');

        // No aria-label left to outrank the <label for="status">.
        expect(trigger.attributes('aria-label')).toBeUndefined();
        expect(trigger.attributes('tabindex')).toBe('0');
        expect(trigger.attributes('aria-haspopup')).toBe('listbox');
        wrapper.unmount();
    });

    it('keeps a name the caller gives it', () => {
        const wrapper = mount(Field, {
            props: { ariaLabel: 'Filter by status' },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        expect(wrapper.get('#status').attributes('aria-label')).toBe(
            'Filter by status',
        );
        wrapper.unmount();
    });

    it('keeps the order of a list whose values are numeric ids', async () => {
        const wrapper = mount(KinetixCombobox, {
            props: {
                options: [
                    { value: '20', label: 'Aguascalientes' },
                    { value: '3', label: 'Jalisco' },
                ],
            },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        const labels = [
            ...document.body.querySelectorAll('[role="option"]'),
        ].map((option) => option.textContent?.trim());
        expect(labels).toEqual(['Aguascalientes', 'Jalisco']);
        wrapper.unmount();
    });

    it('loads from its own source when opened, and shows a known label', async () => {
        const fetchOptions = vi
            .fn()
            .mockResolvedValue([{ value: '7', label: 'Zapopan' }]);
        const wrapper = mount(KinetixCombobox, {
            props: { value: '9', valueLabel: 'Guadalajara', fetchOptions },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        expect(wrapper.get('button').text()).toContain('Guadalajara');

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(fetchOptions).toHaveBeenCalledWith('');
        expect(document.body.textContent).toContain('Zapopan');
        wrapper.unmount();
    });
});
