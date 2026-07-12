import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import KinetixSheet from '@/components/KinetixSheet.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: { close: 'Close' } } },
});

const mountSheet = async (props: Record<string, unknown> = {}) => {
    const wrapper = mount(KinetixSheet, {
        props: { open: true, ...props },
        slots: { default: '<p>Sheet body</p>' },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
    await nextTick();

    return wrapper;
};

describe('KinetixSheet', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders nothing when closed', async () => {
        await mountSheet({ open: false });
        expect(document.body.textContent).not.toContain('Sheet body');
    });

    it('renders the title, description and slot content when open', async () => {
        await mountSheet({ title: 'Event details', description: 'June 15' });
        expect(document.body.textContent).toContain('Event details');
        expect(document.body.textContent).toContain('June 15');
        expect(document.body.textContent).toContain('Sheet body');
    });

    it('defaults to the right side', async () => {
        await mountSheet();
        const panel = document.body.querySelector(
            '[role="dialog"] .shadow-2xl',
        );
        expect(panel?.className).toContain('right-0');
    });

    it('slides from the requested side', async () => {
        await mountSheet({ side: 'left' });
        const panel = document.body.querySelector(
            '[role="dialog"] .shadow-2xl',
        );
        expect(panel?.className).toContain('left-0');
    });

    it('emits update:open and close when the close button is clicked', async () => {
        const w = await mountSheet();
        const closeButton = document.body.querySelector(
            'button[aria-label="Close"]',
        ) as HTMLElement;
        closeButton.click();
        await nextTick();
        expect(w.emitted('update:open')?.[0]).toEqual([false]);
        expect(w.emitted('close')).toBeTruthy();
    });

    it('closes on Escape', async () => {
        const w = await mountSheet();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.emitted('close')).toBeTruthy();
    });

    it('closes when the overlay is clicked', async () => {
        const w = await mountSheet();
        const overlay = document.body.querySelector(
            '[role="dialog"] .bg-black\\/50',
        ) as HTMLElement;
        overlay.click();
        await nextTick();
        expect(w.emitted('close')).toBeTruthy();
    });
});
