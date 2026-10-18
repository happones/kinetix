import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import KinetixTableFilters from '@/components/Table/KinetixTableFilters.vue';
import { i18n } from './i18n';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixTableFilters', () => {
    it('keeps a long list of filters on screen, scrolling inside', async () => {
        const wrapper = mount(KinetixTableFilters, {
            props: {
                filters: Array.from({ length: 20 }, (_, i) => ({
                    name: `f${i}`,
                    label: `Filter ${i}`,
                    type: 'select',
                    default: null,
                    options: { a: 'A' },
                })) as never,
                activeFilters: {},
            },
            attachTo: document.body,
            global: { plugins: [i18n], stubs: { KinetixSelect: true } },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        // Unbounded, a tall panel opened above its trigger with its top
        // filters off-screen and out of reach.
        const panel = document.querySelector('.w-72') as HTMLElement;
        expect(panel.className).toContain(
            'max-h-(--reka-popover-content-available-height)',
        );
        expect(panel.className).toContain('overflow-y-auto');
        wrapper.unmount();
    });
});
