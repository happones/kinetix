import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { pageProps, fetchMock } = vi.hoisted(() => ({
    pageProps: {} as Record<string, unknown>,
    fetchMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixCombobox from '@/components/KinetixCombobox.vue';
import KinetixRelationManager from '@/components/KinetixRelationManager.vue';
import KinetixCheckboxList from '@/components/KinetixCheckboxList.vue';
import { useKinetixSearchLimits } from '@/composables/useKinetixSearchLimits';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                search_min_chars: 'Type at least {count} characters to search.',
            },
        },
    },
});

const mountList = () =>
    mount(KinetixCheckboxList, {
        props: { searchable: true, searchToken: 'tok' },
        global: { plugins: [i18n] },
    });

describe('search limits', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
        delete pageProps.kinetix_config;
        fetchMock.mockResolvedValue({ options: { '1': 'Apple' } });
    });

    it('tolerates a server that shares no limits', () => {
        let limits!: ReturnType<typeof useKinetixSearchLimits>;
        mount({
            setup() {
                limits = useKinetixSearchLimits();

                return () => null;
            },
        });

        expect(limits.maxLength.value).toBeUndefined();
        expect(limits.minChars.value).toBe(1);
        expect(limits.isTooShort('a')).toBe(false);
    });

    it('puts maxlength on the checkbox list search and skips short terms', async () => {
        vi.useFakeTimers();
        pageProps.kinetix_config = {
            search: { min_chars: 3, max_length: 40 },
        };
        const w = mountList();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1); // empty term: initial list

        const input = w.get('input[type="text"]');
        expect(input.attributes('maxlength')).toBe('40');

        await input.setValue('ap');
        await vi.advanceTimersByTimeAsync(300);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const hint = w.get('[role="status"]');
        expect(hint.text()).toBe('Type at least 3 characters to search.');
        expect(input.attributes('aria-describedby')).toBe(
            hint.attributes('id'),
        );
        expect(input.attributes('disabled')).toBeUndefined();

        await input.setValue('app');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(w.get('[role="status"]').text()).toBe('');
        vi.useRealTimers();
    });

    it('renders the hint when the server answers meta.below_min', async () => {
        vi.useFakeTimers();
        const w = mountList();
        await vi.advanceTimersByTimeAsync(0);

        fetchMock.mockResolvedValueOnce({
            options: {},
            meta: { below_min: true, min_chars: 2 },
        });
        await w.get('input[type="text"]').setValue('x');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(w.get('[role="status"]').text()).toBe(
            'Type at least 2 characters to search.',
        );
        vi.useRealTimers();
    });

    it('hides the checkbox list hint region when it is not searchable', () => {
        const w = mount(KinetixCheckboxList, {
            props: { options: { '1': 'Apple' } },
            global: { plugins: [i18n] },
        });

        expect(w.find('[role="status"]').exists()).toBe(false);
    });

    describe('combobox', () => {
        const openCombobox = async (props: Record<string, unknown>) => {
            const w = mount(KinetixCombobox, {
                props,
                attachTo: document.body,
                global: { plugins: [i18n] },
            });
            await w.get('button').trigger('click');
            await vi.advanceTimersByTimeAsync(0);

            const input = document.body.querySelector(
                'input',
            ) as HTMLInputElement;
            const type = async (value: string) => {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                await vi.advanceTimersByTimeAsync(300);
            };

            return { w, input, type };
        };

        it('caps the term, skips short ones on the search endpoint and hints outside the listbox', async () => {
            vi.useFakeTimers();
            pageProps.kinetix_config = {
                search: { min_chars: 3, max_length: 25 },
            };
            const { w, input, type } = await openCombobox({
                searchToken: 'tok',
            });
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(input.getAttribute('maxlength')).toBe('25');

            await type('ab');

            expect(fetchMock).toHaveBeenCalledTimes(1);
            const status = document.body.querySelector('[role="status"]');
            expect(status?.textContent?.trim()).toBe(
                'Type at least 3 characters to search.',
            );
            expect(status?.closest('[role="listbox"]')).toBeNull();
            expect(input.getAttribute('aria-describedby')).toBe(status?.id);
            expect(input.disabled).toBe(false);

            w.unmount();
            vi.useRealTimers();
        });

        it('shows the hint for a server below_min answer', async () => {
            vi.useFakeTimers();
            const { w, type } = await openCombobox({ searchToken: 'tok' });

            fetchMock.mockResolvedValueOnce({
                options: {},
                meta: { below_min: true, min_chars: 2 },
            });
            await type('x');

            expect(
                document.body.querySelector('[role="status"]')?.textContent,
            ).toContain('Type at least 2 characters');

            w.unmount();
            vi.useRealTimers();
        });

        it('still sends short terms to a custom fetchOptions source', async () => {
            vi.useFakeTimers();
            pageProps.kinetix_config = {
                search: { min_chars: 3, max_length: null },
            };
            const fetchOptions = vi.fn().mockResolvedValue([]);
            const { w, type } = await openCombobox({ fetchOptions });

            await type('ab');

            expect(fetchOptions).toHaveBeenLastCalledWith('ab', {
                signal: expect.any(AbortSignal),
            });
            expect(
                document.body.querySelector('[role="status"]')?.textContent,
            ).toBe('');

            w.unmount();
            vi.useRealTimers();
        });
    });

    describe('relation manager picker', () => {
        it('caps the term, skips short ones and shows the hint', async () => {
            vi.useFakeTimers();
            pageProps.kinetix_config = {
                search: { min_chars: 3, max_length: 30 },
            };
            fetchMock.mockResolvedValue({ options: [] });
            const w = mount(KinetixRelationManager, {
                props: {
                    manager: {
                        title: 'Tags',
                        relationship: 'tags',
                        descriptor: 'signed',
                        table: { queryPrefix: 'tags_' },
                    } as never,
                },
                attachTo: document.body,
                global: {
                    plugins: [i18n],
                    stubs: { KinetixTable: true },
                },
            });

            window.dispatchEvent(
                new CustomEvent('kinetix:open-attach', {
                    detail: { relationship: 'tags' },
                }),
            );
            await vi.advanceTimersByTimeAsync(0);
            expect(fetchMock).toHaveBeenCalledTimes(1);

            const input = document.body.querySelector(
                'input[type="search"]',
            ) as HTMLInputElement;
            expect(input.getAttribute('maxlength')).toBe('30');

            input.value = 'ab';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            await vi.advanceTimersByTimeAsync(400);

            expect(fetchMock).toHaveBeenCalledTimes(1);
            const status = document.body.querySelector('[role="status"]');
            expect(status?.textContent?.trim()).toBe(
                'Type at least 3 characters to search.',
            );
            expect(input.getAttribute('aria-describedby')).toBe(status?.id);

            w.unmount();
            document.body.innerHTML = '';
            vi.useRealTimers();
        });
    });
});
