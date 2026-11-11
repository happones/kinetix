import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { pageProps, fetchMock, prefix } = vi.hoisted(() => ({
    pageProps: {} as Record<string, unknown>,
    fetchMock: vi.fn(),
    prefix: { value: '_kinetix' },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { reload: vi.fn() },
}));
vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => prefix.value,
}));

import { toast } from 'vue-sonner';
import KinetixCheckboxList from '@/components/KinetixCheckboxList.vue';
import KinetixCombobox from '@/components/KinetixCombobox.vue';
import KinetixRelationManager from '@/components/KinetixRelationManager.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                search_min_chars: 'Type at least {count} characters.',
                search_results_count: '{count} result | {count} results',
                search_throttled_retrying: 'Too many searches, retrying.',
                search_timed_out: 'The search took too long.',
                action_failed: 'Action failed',
                search_records: 'Search records',
                attach_none_found: 'None',
            },
        },
    },
});

const busy = (retryAfter = 1) =>
    Object.assign(new Error('Busy'), {
        status: 429,
        code: 'busy',
        retryAfter,
    });

const live = () => document.getElementById('kinetix-live-region');

const openCombobox = async (props: Record<string, unknown>) => {
    const w = mount(KinetixCombobox, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    await w.get('button').trigger('click');
    await vi.advanceTimersByTimeAsync(0);

    const input = document.body.querySelector('input') as HTMLInputElement;
    const type = async (value: string) => {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(300);
    };

    return { w, input, type };
};

describe('lookup resilience', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        prefix.value = '_kinetix';
        delete pageProps.kinetix_config;
        live()?.remove();
        fetchMock.mockImplementation(async (_url: string, init: any) => ({
            options: { '1': `r:${init?.body?.q ?? ''}` },
        }));
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    describe('combobox', () => {
        it('answers a reopened popup from the cache instead of asking again', async () => {
            const first = await openCombobox({ searchToken: 'tok' });
            expect(fetchMock).toHaveBeenCalledTimes(1);
            first.w.unmount();
            document.body.innerHTML = '';

            const second = await openCombobox({ searchToken: 'tok' });
            expect(fetchMock).toHaveBeenCalledTimes(1);
            second.w.unmount();
        });

        it('never serves another team or another token from the cache', async () => {
            const a = await openCombobox({ searchToken: 'tok' });
            a.w.unmount();
            document.body.innerHTML = '';

            prefix.value = 'other-team/_kinetix';
            const b = await openCombobox({ searchToken: 'tok' });
            expect(fetchMock).toHaveBeenCalledTimes(2);
            expect(fetchMock.mock.calls[1][0]).toBe(
                '/other-team/_kinetix/forms/search',
            );
            b.w.unmount();
            document.body.innerHTML = '';

            prefix.value = '_kinetix';
            const c = await openCombobox({ searchToken: 'other-token' });
            expect(fetchMock).toHaveBeenCalledTimes(3);
            c.w.unmount();
        });

        it('does not cache a custom fetchOptions source', async () => {
            const fetchOptions = vi.fn().mockResolvedValue([]);
            const a = await openCombobox({ fetchOptions });
            a.w.unmount();
            document.body.innerHTML = '';
            const b = await openCombobox({ fetchOptions });

            expect(fetchOptions).toHaveBeenCalledTimes(2);
            b.w.unmount();
        });

        it('marks the list busy while loading, never disabling the input', async () => {
            let release!: (value: unknown) => void;
            fetchMock.mockImplementationOnce(
                () => new Promise((resolve) => (release = resolve)),
            );
            const { w, input } = await openCombobox({ searchToken: 'tok' });

            const viewport = document.body.querySelector('[aria-busy]');
            expect(viewport?.getAttribute('aria-busy')).toBe('true');
            expect(input.disabled).toBe(false);

            release({ options: { '1': 'One' } });
            await flushPromises();
            expect(
                document.body.querySelector('[aria-busy="true"]'),
            ).toBeNull();
            w.unmount();
        });

        it('keeps the list, says it is retrying, and succeeds on the retry (busy)', async () => {
            const { w, type } = await openCombobox({ searchToken: 'tok' });
            fetchMock.mockRejectedValueOnce(busy(1));

            await type('ab');
            expect(document.body.textContent).toContain(
                'Too many searches, retrying.',
            );
            await vi.advanceTimersByTimeAsync(30);
            expect(live()?.textContent).toBe('Too many searches, retrying.');

            await vi.advanceTimersByTimeAsync(1000);
            await vi.advanceTimersByTimeAsync(30);

            expect(document.body.textContent).not.toContain(
                'Too many searches, retrying.',
            );
            expect(fetchMock).toHaveBeenCalledTimes(3);
            expect(live()?.textContent).toBe('1 result');
            w.unmount();
        });

        it('announces the count once a search settles, not per keystroke', async () => {
            const { w, input } = await openCombobox({ searchToken: 'tok' });
            await vi.advanceTimersByTimeAsync(30);
            const initial = live()?.textContent;
            expect(initial).toBe('1 result');

            for (const value of ['a', 'ap', 'app']) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                await vi.advanceTimersByTimeAsync(50);
            }
            expect(fetchMock).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(300);
            expect(fetchMock).toHaveBeenCalledTimes(2);
            w.unmount();
        });

        it('says so when the server timed out', async () => {
            const { w, type } = await openCombobox({ searchToken: 'tok' });
            fetchMock.mockResolvedValueOnce({
                options: {},
                meta: { timed_out: true },
            });

            await type('ab');
            await vi.advanceTimersByTimeAsync(30);

            expect(document.body.textContent).toContain(
                'The search took too long.',
            );
            expect(live()?.textContent).toBe('The search took too long.');
            w.unmount();
        });
    });

    describe('checkbox list', () => {
        const mountList = () =>
            mount(KinetixCheckboxList, {
                props: { searchable: true, searchToken: 'tok' },
                global: { plugins: [i18n] },
            });

        it('retries a busy answer once and then shows the options', async () => {
            fetchMock.mockRejectedValueOnce(busy(1));
            const w = mountList();
            await vi.advanceTimersByTimeAsync(0);

            expect(w.text()).toContain('Too many searches, retrying.');

            await vi.advanceTimersByTimeAsync(1000);
            await flushPromises();

            expect(w.text()).not.toContain('retrying');
            expect(w.text()).toContain('r:');
            w.unmount();
        });

        it('does not announce its initial load, but announces a typed search', async () => {
            const w = mountList();
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(30);

            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(live()).toBeNull();

            await w.get('input[type="text"]').setValue('ap');
            await vi.advanceTimersByTimeAsync(300);
            await vi.advanceTimersByTimeAsync(30);

            expect(live()?.textContent).toBe('1 result');
            w.unmount();
        });

        it('marks the options busy while loading', async () => {
            let release!: (value: unknown) => void;
            fetchMock.mockImplementationOnce(
                () => new Promise((resolve) => (release = resolve)),
            );
            const w = mountList();
            await vi.advanceTimersByTimeAsync(0);

            expect(w.find('[aria-busy="true"]').exists()).toBe(true);
            expect(w.get('input').attributes('disabled')).toBeUndefined();

            release({ options: { '1': 'One' } });
            await flushPromises();
            expect(w.find('[aria-busy="true"]').exists()).toBe(false);
            w.unmount();
        });
    });

    describe('relation manager picker', () => {
        const mountManager = () =>
            mount(KinetixRelationManager, {
                props: {
                    manager: {
                        title: 'Tags',
                        relationship: 'tags',
                        descriptor: 'signed',
                        table: { queryPrefix: 'tags_' },
                    } as never,
                },
                attachTo: document.body,
                global: { plugins: [i18n], stubs: { KinetixTable: true } },
            });

        const openPicker = async () => {
            window.dispatchEvent(
                new CustomEvent('kinetix:open-attach', {
                    detail: { relationship: 'tags' },
                }),
            );
            await vi.advanceTimersByTimeAsync(0);
        };

        const options = (
            url: string,
        ): Array<{ id: number; label: string }> | undefined =>
            url.endsWith('attachable') ? [{ id: 1, label: 'php' }] : undefined;

        it('reuses the picker list until a detach drops the manager namespace', async () => {
            fetchMock.mockImplementation(async (url: string) => ({
                options: options(url) ?? [],
            }));
            const w = mountManager();

            await openPicker();
            expect(fetchMock).toHaveBeenCalledTimes(1);

            window.dispatchEvent(
                new CustomEvent('kinetix:open-attach', {
                    detail: { relationship: 'tags' },
                }),
            );
            await vi.advanceTimersByTimeAsync(0);
            expect(fetchMock).toHaveBeenCalledTimes(1); // cached

            window.dispatchEvent(
                new CustomEvent('kinetix:detach-relation', {
                    detail: { relationship: 'tags', ids: [1] },
                }),
            );
            await vi.advanceTimersByTimeAsync(0);
            await flushPromises();
            expect(fetchMock).toHaveBeenCalledTimes(2); // the detach itself

            await openPicker();
            expect(fetchMock).toHaveBeenCalledTimes(3); // fresh list
            w.unmount();
        });

        it('toasts a failed lookup of the newest search, not a retrying throttle', async () => {
            fetchMock.mockRejectedValueOnce(busy(1));
            const w = mountManager();
            await openPicker();

            expect(toast.error).not.toHaveBeenCalled();
            expect(document.body.textContent).toContain(
                'Too many searches, retrying.',
            );

            fetchMock.mockReset();
            fetchMock.mockRejectedValue(
                Object.assign(new Error('Server on fire'), { status: 500 }),
            );
            window.dispatchEvent(
                new CustomEvent('kinetix:open-attach', {
                    detail: { relationship: 'tags' },
                }),
            );
            await vi.advanceTimersByTimeAsync(0);
            await flushPromises();

            expect(toast.error).toHaveBeenCalledWith('Server on fire');
            w.unmount();
        });
    });
});
