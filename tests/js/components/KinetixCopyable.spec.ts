import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { i18n } from './i18n';
import KinetixCopyable from '@/components/primitives/KinetixCopyable.vue';
import {
    useKinetixClipboard,
    writeToClipboard,
} from '@/composables/useKinetixClipboard';

const writeText = vi.fn();

const tooltipText = (): string | null =>
    document.body.querySelector('[role="tooltip"]')?.textContent?.trim() ??
    null;

const liveRegion = (): HTMLElement | null =>
    document.getElementById('kinetix-live-region');

const mountIt = (slot?: string) =>
    mount(KinetixCopyable, {
        props: { value: 'ada@acme.dev' },
        slots: slot ? { default: () => h('span', slot) } : {},
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

/** Lets the announcer's requestAnimationFrame write its message. */
const nextFrame = (): Promise<void> =>
    new Promise((resolve) => requestAnimationFrame(() => resolve()));

beforeEach(() => {
    writeText.mockReset().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('KinetixCopyable', () => {
    it('makes the whole value the trigger and copies it on click', async () => {
        const w = mountIt('ada@acme.dev');
        const button = w.get('button');

        expect(button.attributes('type')).toBe('button');
        expect(button.text()).toContain('ada@acme.dev');
        // The value is the accessible name; no aria-label overrides it.
        expect(button.attributes('aria-label')).toBeUndefined();

        await button.trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('ada@acme.dev');
        expect(button.attributes('data-copied')).toBe('');
    });

    it('confirms the copy in the tooltip and to screen readers', async () => {
        const w = mountIt('ada@acme.dev');

        await w.get('button').trigger('click');
        await flushPromises();
        await nextFrame();

        expect(tooltipText()).toBe('Copied!');
        expect(liveRegion()?.textContent).toBe('Copied!');
        expect(liveRegion()?.getAttribute('aria-live')).toBe('polite');
    });

    it('reads "Copy" on hover before the click', async () => {
        vi.useFakeTimers();
        const w = mountIt('ada@acme.dev');

        await w.get('button').trigger('pointermove', { pointerType: 'mouse' });
        vi.advanceTimersByTime(300);
        await nextTick();

        expect(tooltipText()).toBe('Copy');
    });

    it('ignores a pointer focus (a tap) so it never pins the tooltip open', async () => {
        const w = mountIt('ada@acme.dev');

        await w.get('button').trigger('focus');
        await nextTick();

        expect(tooltipText()).toBeNull();
    });

    it('closes a tooltip that only opened to confirm, once it expires', async () => {
        vi.useFakeTimers();
        const w = mountIt('ada@acme.dev');

        await w.get('button').trigger('click');
        await flushPromises();
        expect(tooltipText()).toBe('Copied!');

        vi.advanceTimersByTime(2000);
        await nextTick();

        expect(w.get('button').attributes('data-copied')).toBeUndefined();
        expect(w.get('button').attributes('data-state')).toBe('closed');
    });

    it('renders a labelled icon-only button when there is no content', async () => {
        const w = mountIt();
        const button = w.get('button');

        expect(button.attributes('aria-label')).toBe('Copy');

        await button.trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('ada@acme.dev');
        expect(button.attributes('aria-label')).toBe('Copied!');
    });

    it('says so when the clipboard refuses, assertively', async () => {
        writeText.mockRejectedValue(new Error('denied'));
        Object.defineProperty(document, 'execCommand', {
            value: vi.fn().mockReturnValue(false),
            configurable: true,
        });
        const w = mountIt('ada@acme.dev');

        await w.get('button').trigger('click');
        await flushPromises();
        await nextFrame();

        expect(tooltipText()).toBe("Couldn't copy");
        expect(w.get('button').attributes('data-copied')).toBeUndefined();
        expect(liveRegion()?.getAttribute('aria-live')).toBe('assertive');
    });
});

describe('useKinetixClipboard', () => {
    it('falls back to the selection copy outside a secure context', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: undefined,
            configurable: true,
        });
        const execCommand = vi.fn().mockReturnValue(true);
        Object.defineProperty(document, 'execCommand', {
            value: execCommand,
            configurable: true,
        });

        await expect(writeToClipboard('sk-123')).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('copy');
        // The helper textarea never lingers in the page.
        expect(document.querySelector('textarea')).toBeNull();
    });

    it('resets to idle after the confirmation and clears the timer on unmount', async () => {
        vi.useFakeTimers();
        let clipboard!: ReturnType<typeof useKinetixClipboard>;
        const w = mount(
            defineComponent({
                setup() {
                    clipboard = useKinetixClipboard(1500);

                    return () => null;
                },
            }),
        );

        await clipboard.copy('x');
        expect(clipboard.status.value).toBe('copied');
        expect(vi.getTimerCount()).toBe(1);

        vi.advanceTimersByTime(1500);
        expect(clipboard.status.value).toBe('idle');

        await clipboard.copy('x');
        w.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
});
