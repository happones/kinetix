import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixCheckboxList from '@/components/KinetixCheckboxList.vue';
import KinetixCheckbox from '@/components/KinetixCheckbox.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const mountIt = (props = {}) =>
    mount(KinetixCheckboxList, {
        props: {
            options: {
                '1': 'Apple',
                '2': 'Banana',
                '3': 'Cherry',
            },
            ...props,
        },
        global: { plugins: [i18n] },
    });

describe('KinetixCheckboxList', () => {
    it('renders list of checkboxes from options', () => {
        const w = mountIt();
        const text = w.text();
        expect(text).toContain('Apple');
        expect(text).toContain('Banana');
        expect(text).toContain('Cherry');
    });

    it('filters items locally when search input is typed', async () => {
        const w = mountIt({ searchable: true });
        const input = w.find('input[type="text"]');
        expect(input.exists()).toBe(true);

        await input.setValue('ban');
        expect(w.text()).toContain('Banana');
        expect(w.text()).not.toContain('Apple');
        expect(w.text()).not.toContain('Cherry');
    });

    it('keeps selected values visible and checked even if they do not match search query', async () => {
        const w = mountIt({
            searchable: true,
            value: ['1'], // Apple is selected
        });

        const input = w.find('input[type="text"]');
        await input.setValue('ban'); // Cherry is filtered out, Banana matches, Apple is selected so it must stay visible

        expect(w.text()).toContain('Banana');
        expect(w.text()).toContain('Apple');
        expect(w.text()).not.toContain('Cherry');
    });

    it('emits update:value when checkbox is clicked', async () => {
        const w = mountIt({
            value: ['1'],
        });

        // Click Banana checkbox (val '2')
        const checkboxes = w.findAllComponents(KinetixCheckbox);
        // The second checkbox is Banana
        await checkboxes[1].vm.$emit('change', true);

        expect(w.emitted('update:value')).toBeTruthy();
        expect(w.emitted('update:value')?.[0]?.[0]).toEqual(['1', '2']);
    });

    it('fetches remote search results when searchToken is provided', async () => {
        fetchMock.mockResolvedValueOnce({
            options: {
                '10': 'Watermelon',
                '11': 'Grape',
            },
        });

        const w = mountIt({
            searchable: true,
            searchToken: 'dummy_token',
        });

        await flushPromises();

        expect(fetchMock).toHaveBeenCalled();
        expect(w.text()).toContain('Watermelon');
        expect(w.text()).toContain('Grape');
    });

    describe('select all', () => {
        const master = (w: ReturnType<typeof mountIt>) =>
            w.findAllComponents(KinetixCheckbox)[0];

        it('is only there when the field asks for it', () => {
            expect(mountIt().text()).not.toContain('kinetix.select_all');
            expect(mountIt({ bulkToggleable: true }).text()).toContain(
                'kinetix.select_all',
            );
        });

        it('checks every option, then clears them', async () => {
            const w = mountIt({ bulkToggleable: true, value: [] });

            await master(w).vm.$emit('change', true);
            expect(w.emitted('update:value')?.at(-1)?.[0]).toEqual([
                '1',
                '2',
                '3',
            ]);

            await w.setProps({ value: ['1', '2', '3'] });
            expect(master(w).props('checked')).toBe(true);

            await master(w).vm.$emit('change', false);
            expect(w.emitted('update:value')?.at(-1)?.[0]).toEqual([]);
        });

        it('shows a dash over a partial selection, and its count', () => {
            const w = mountIt({ bulkToggleable: true, value: ['2'] });

            expect(master(w).props('checked')).toBe(false);
            expect(master(w).props('indeterminate')).toBe(true);
            // Announced as partly checked, not as unchecked.
            expect(master(w).find('button').attributes('aria-checked')).toBe(
                'mixed',
            );
            expect(w.text()).toContain('1/3');
        });

        it('acts on what the search left, keeping the rest of the selection', async () => {
            const w = mountIt({
                bulkToggleable: true,
                searchable: true,
                value: ['1'],
            });

            await w.find('input[type="text"]').setValue('an'); // Banana only
            await master(w).vm.$emit('change', true);

            // Apple stays; Banana joins; Cherry (filtered out) is untouched.
            expect(w.emitted('update:value')?.at(-1)?.[0]).toEqual(['1', '2']);
        });
    });
});
