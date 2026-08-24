import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixSheet from '@/components/KinetixSheet.vue';
import KinetixModal from '@/components/primitives/KinetixModal.vue';

/**
 * The shell's scroll contract. The regression this guards: the panel had no
 * bound of its own, so `scrollBody` was the ONLY thing between a long form and
 * content stranded off screen — a modal without it grew past the viewport with
 * its title and its footer actions unreachable, nothing left to scroll.
 *
 * Geometry lives in the browser E2E (scripts/e2e-modal-scroll.mjs); this spec
 * pins the DOM contract that produces it, in CI, without a browser.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: { close: 'Close' } } },
});

const mountModal = (props: Record<string, unknown> = {}) =>
    mount(KinetixModal, {
        attachTo: document.body,
        props: { open: true, title: 'Long form', ...props },
        slots: { default: '<input class="kx-field" />' },
        global: { plugins: [i18n] },
    });

const dialog = () =>
    document.body.querySelector('[role="dialog"]') as HTMLElement;
const panel = () =>
    document.body.querySelector(
        '[role="dialog"] [tabindex="-1"]',
    ) as HTMLElement;
const scrollAreas = () =>
    document.body.querySelectorAll('[data-slot="scroll-area-viewport"]');

describe('KinetixModal — the shell is always bounded', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('scrolls the WRAPPER, so a panel taller than the viewport stays reachable', async () => {
        const w = mountModal();
        await w.vm.$nextTick();

        expect(dialog().className).toContain('overflow-y-auto');
        // Without `min-h-full` on the centering row, a wrapper that scrolls
        // clips a centered panel at the top instead of scrolling to it.
        const row = dialog().firstElementChild as HTMLElement;
        expect(row.className).toContain('min-h-full');
        expect(row.className).toContain('items-center');
        w.unmount();
    });

    it('dismisses on the overlay row, not on the panel', async () => {
        const w = mountModal();
        await w.vm.$nextTick();

        (panel().querySelector('.kx-field') as HTMLElement).click();
        expect(w.emitted('update:open')).toBeFalsy();

        (dialog().firstElementChild as HTMLElement).click();
        expect(w.emitted('update:open')?.[0]).toEqual([false]);
        w.unmount();
    });

    it('keeps the whole panel in flow by default — no clipped body', async () => {
        const w = mountModal();
        await w.vm.$nextTick();

        expect(panel().className).not.toContain('max-h-');
        // Nothing clips: an in-flow dropdown inside the body can still escape.
        expect(scrollAreas().length).toBe(0);
        expect(document.body.querySelector('.kx-field')).not.toBeNull();
        w.unmount();
    });

    it('scrollBody bounds the panel and scrolls the body in a shadcn ScrollArea', async () => {
        const w = mountModal({ scrollBody: true });
        await w.vm.$nextTick();

        expect(panel().className).toContain('max-h-[calc(100dvh-2rem)]');
        expect(panel().className).toContain(
            'grid-rows-[auto_minmax(0,1fr)_auto]',
        );

        const viewport = scrollAreas()[0] as HTMLElement;
        expect(viewport).toBeTruthy();
        expect(viewport.querySelector('.kx-field')).not.toBeNull();
        // The overlay bar rides in the panel's padding gutter, not on the
        // fields — the reka viewport hides the native scrollbar.
        expect(
            (viewport.closest('[data-slot="scroll-area"]') as HTMLElement)
                .className,
        ).toContain('-mr-3');
        w.unmount();
    });
});

describe('KinetixSheet — the body is the scroller', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('pins the header and footer and scrolls the body in a ScrollArea', async () => {
        const w = mount(KinetixSheet, {
            attachTo: document.body,
            props: { open: true, title: 'Long form' },
            slots: {
                default: '<input class="kx-field" />',
                footer: '<button type="submit">Save</button>',
            },
            global: { plugins: [i18n] },
        });
        await w.vm.$nextTick();

        const sheetPanel = document.body.querySelector(
            '[role="dialog"] [tabindex="-1"]',
        ) as HTMLElement;
        // The panel itself must NOT scroll — that scrolls the footer away.
        expect(sheetPanel.className).toContain('overflow-hidden');
        expect(sheetPanel.className).not.toContain('overflow-y-auto');

        const viewport = document.body.querySelector(
            '[data-slot="scroll-area-viewport"]',
        ) as HTMLElement;
        expect(viewport.querySelector('.kx-field')).not.toBeNull();
        expect(viewport.querySelector('button[type="submit"]')).toBeNull();
        w.unmount();
    });
});
