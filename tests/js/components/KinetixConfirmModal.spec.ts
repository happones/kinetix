import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixConfirmModal from '@/components/KinetixConfirmModal.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                confirm: 'Confirm',
                cancel: 'Cancel',
                confirm_heading: 'Are you sure?',
            },
        },
    },
});

// The modal teleports to <body>, so attach there and query the document.
const mountModal = (props: Record<string, unknown> = {}) =>
    mount(KinetixConfirmModal, {
        attachTo: document.body,
        props: { open: true, ...props },
        global: { plugins: [i18n] },
    });

const buttons = () => [...document.body.querySelectorAll('button')];
const byText = (text: string) =>
    buttons().find((b) => b.textContent?.trim() === text) as HTMLButtonElement;

describe('KinetixConfirmModal', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('emits confirm WITHOUT self-closing — the parent controls `open`', async () => {
        const w = mountModal();
        await w.vm.$nextTick();

        byText('Confirm').click();
        await w.vm.$nextTick();

        expect(w.emitted('confirm')).toBeTruthy();
        // Must not close itself, so the parent can keep it open while awaiting.
        expect(w.emitted('update:open')).toBeFalsy();
        w.unmount();
    });

    it('cancel closes the modal and emits cancel', async () => {
        const w = mountModal();
        await w.vm.$nextTick();

        byText('Cancel').click();
        await w.vm.$nextTick();

        expect(w.emitted('cancel')).toBeTruthy();
        expect(w.emitted('update:open')?.[0]).toEqual([false]);
        w.unmount();
    });

    it('moves focus into the panel and labels the dialog by its heading', async () => {
        const w = mountModal();
        await w.vm.$nextTick();
        await w.vm.$nextTick();

        const dialog = document.body.querySelector('[role="dialog"]')!;
        const heading = document.body.querySelector('h2')!;
        expect(dialog.getAttribute('aria-labelledby')).toBe(heading.id);

        const panel = document.body.querySelector(
            '[role="dialog"] > div + div',
        );
        expect(panel?.contains(document.activeElement)).toBe(true);
        w.unmount();
    });

    it('restores focus to the opener when it closes', async () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();

        const w = mountModal();
        await w.vm.$nextTick();
        await w.vm.$nextTick();
        expect(document.activeElement).not.toBe(opener);

        await w.setProps({ open: false });
        expect(document.activeElement).toBe(opener);
        w.unmount();
    });

    it('while processing: buttons disable and confirm/cancel are no-ops', async () => {
        const w = mountModal({ processing: true });
        await w.vm.$nextTick();

        const confirmBtn = buttons().find((b) =>
            b.textContent?.includes('Confirm'),
        ) as HTMLButtonElement;
        const cancelBtn = byText('Cancel');

        expect(confirmBtn.disabled).toBe(true);
        expect(cancelBtn.disabled).toBe(true);

        confirmBtn.click();
        cancelBtn.click();
        await w.vm.$nextTick();

        expect(w.emitted('confirm')).toBeFalsy();
        expect(w.emitted('cancel')).toBeFalsy();
        w.unmount();
    });
});
