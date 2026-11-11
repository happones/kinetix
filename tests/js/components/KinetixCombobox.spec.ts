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

/** A promise this test settles by hand, to stage out-of-order responses. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

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

        expect(fetchOptions).toHaveBeenCalledWith('', {
            signal: expect.any(AbortSignal),
        });
        expect(document.body.textContent).toContain('Zapopan');
        wrapper.unmount();
    });

    it("shows the newest term's options when an older lookup answers last", async () => {
        vi.useFakeTimers();
        const slow = deferred<{ value: string; label: string }[]>();
        const fast = deferred<{ value: string; label: string }[]>();
        const fetchOptions = vi
            .fn()
            .mockResolvedValueOnce([]) // the lookup on open
            .mockReturnValueOnce(slow.promise)
            .mockReturnValueOnce(fast.promise);
        const wrapper = mount(KinetixCombobox, {
            props: { fetchOptions },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await wrapper.get('button').trigger('click');
        await vi.advanceTimersByTimeAsync(0);

        const input = document.body.querySelector('input') as HTMLInputElement;
        input.value = 'za';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(250);
        input.value = 'zapo';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(250);

        fast.resolve([{ value: '1', label: 'Zapopan' }]);
        await vi.advanceTimersByTimeAsync(0);
        slow.resolve([{ value: '2', label: 'Zacatecas' }]);
        await vi.advanceTimersByTimeAsync(0);

        expect(document.body.textContent).toContain('Zapopan');
        expect(document.body.textContent).not.toContain('Zacatecas');
        const first = fetchOptions.mock.calls[1][1] as { signal: AbortSignal };
        expect(first.signal.aborted).toBe(true);

        wrapper.unmount();
        vi.useRealTimers();
    });

    it('never shows a network failure or a bare status line to the user', async () => {
        const fetchOptions = vi
            .fn()
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockRejectedValueOnce(
                Object.assign(new Error('HTTP error! status: 500'), {
                    status: 500,
                }),
            );

        const wrapper = mount(KinetixCombobox, {
            props: { fetchOptions },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[role="alert"]')?.textContent).toBe(
            'kinetix.action_failed',
        );
        wrapper.unmount();
    });

    it('says why a lookup failed inside the list instead of failing silently', async () => {
        const fetchOptions = vi.fn().mockRejectedValue(
            Object.assign(new Error('Too many requests'), {
                status: 429,
                retryAfter: 60,
            }),
        );
        const unhandled = vi.fn();
        process.on('unhandledRejection', unhandled);

        const wrapper = mount(KinetixCombobox, {
            props: { fetchOptions },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[role="alert"]')?.textContent).toBe(
            'Too many requests',
        );
        expect(unhandled).not.toHaveBeenCalled();
        process.off('unhandledRejection', unhandled);
        wrapper.unmount();
    });

    it('aborts the in-flight lookup when the popup closes', async () => {
        const never = deferred<{ value: string; label: string }[]>();
        const fetchOptions = vi.fn().mockReturnValue(never.promise);
        const wrapper = mount(KinetixCombobox, {
            props: { fetchOptions },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();
        const { signal } = fetchOptions.mock.calls[0][1] as {
            signal: AbortSignal;
        };
        expect(signal.aborted).toBe(false);

        document.body.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        const input = document.body.querySelector('input') as HTMLInputElement;
        input.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();

        expect(signal.aborted).toBe(true);
        wrapper.unmount();
    });
});
