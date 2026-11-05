import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
    router: { visit: vi.fn() },
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    isKinetixAbort: () => false,
    kinetixFetch: vi.fn().mockResolvedValue({ groups: [] }),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixShortcuts from '@/components/KinetixShortcuts.vue';
import KinetixSpotlight from '@/components/KinetixSpotlight.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                spotlight_placeholder: 'Search…',
                shortcuts_title: 'Keyboard shortcuts',
            },
        },
    },
});

const mounted: { unmount(): void }[] = [];

function mountIt<T>(component: T, attrs: Record<string, unknown> = {}) {
    const wrapper = mount(component as never, {
        attrs,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

function press(init: KeyboardEventInit): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        bubbles: true,
        cancelable: true,
        ...init,
    });
    window.dispatchEvent(event);

    return event;
}

const dialog = (): HTMLElement | null =>
    document.body.querySelector('[role="dialog"]');

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

describe('KinetixSpotlight host', () => {
    beforeEach(() => {
        state.props = { kinetix_config: { spotlight: { min_chars: 2 } } };
    });

    it('listens to nothing and loads nothing with the palette off', async () => {
        state.props = {
            kinetix_config: { spotlight: { min_chars: 2, enabled: false } },
        };
        const wrapper = mountIt(KinetixSpotlight);

        const event = press({ key: 'k', ctrlKey: true });
        window.dispatchEvent(new CustomEvent('kinetix:spotlight'));
        await flushPromises();

        expect(event.defaultPrevented).toBe(false);
        expect(dialog()).toBeNull();
        expect(wrapper.findComponent({ name: 'SpotlightBody' }).exists()).toBe(
            false,
        );
    });

    it('renders nothing until the first opening', () => {
        const wrapper = mountIt(KinetixSpotlight);

        expect(wrapper.html()).toBe('<!--v-if-->');
        expect(dialog()).toBeNull();
    });

    it('claims the first Cmd/Ctrl+K at once and opens the palette once loaded, focused', async () => {
        const trigger = document.createElement('button');
        document.body.appendChild(trigger);
        trigger.focus();
        const wrapper = mountIt(KinetixSpotlight);

        const event = press({ key: 'k', metaKey: true });
        expect(event.defaultPrevented).toBe(true);

        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        await vi.waitFor(() =>
            expect(
                dialog()!.contains(document.activeElement) &&
                    document.activeElement?.tagName,
            ).toBe('INPUT'),
        );

        // Closed by the palette (Escape, a selection): focus goes back.
        wrapper
            .findComponent({ name: 'SpotlightBody' })
            .vm.$emit('update:open', false);
        await vi.waitFor(() => expect(document.activeElement).toBe(trigger));
        expect(dialog()).toBeNull();
    });

    it('opens on the kinetix:spotlight event (a header trigger)', async () => {
        mountIt(KinetixSpotlight);

        window.dispatchEvent(new CustomEvent('kinetix:spotlight'));

        await vi.waitFor(() => expect(dialog()).not.toBeNull());
    });

    it('forwards attributes to the palette', async () => {
        const wrapper = mountIt(KinetixSpotlight, { 'data-testid': 'palette' });

        window.dispatchEvent(new CustomEvent('kinetix:spotlight'));
        await vi.waitFor(() =>
            expect(
                wrapper.findComponent({ name: 'SpotlightBody' }).exists(),
            ).toBe(true),
        );

        expect(
            wrapper.findComponent({ name: 'SpotlightBody' }).vm.$attrs,
        ).toMatchObject({ 'data-testid': 'palette' });
    });

    it('removes its listeners on unmount', () => {
        const remove = vi.spyOn(window, 'removeEventListener');
        const wrapper = mountIt(KinetixSpotlight);

        wrapper.unmount();
        mounted.splice(0);

        expect(remove).toHaveBeenCalledWith('keydown', expect.any(Function));
        expect(remove).toHaveBeenCalledWith(
            'kinetix:spotlight',
            expect.any(Function),
        );
        remove.mockRestore();
    });
});

describe('KinetixShortcuts host', () => {
    it('answers `?` before the sheet has loaded, and opens it once it has', async () => {
        const wrapper = mountIt(KinetixShortcuts);

        expect(wrapper.html()).toBe('<!--v-if-->');

        const event = new KeyboardEvent('keydown', {
            key: '?',
            bubbles: true,
            cancelable: true,
        });
        document.body.dispatchEvent(event);
        window.dispatchEvent(event);

        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        expect(dialog()!.textContent).toContain('Keyboard shortcuts');
    });
});
