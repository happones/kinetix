import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), post: vi.fn(), put: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    usePoll: vi.fn(),
}));

/**
 * The record modal's form downloads on first open. Nothing is preloaded here:
 * each case gets a fresh module graph, so the form is cold every time.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                cancel: 'Cancel',
                save: 'Save',
                create: 'Create',
                close: 'Close',
            },
        },
    },
});

const table = {
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'App\\Models\\Post',
    columns: [
        {
            name: 'name',
            label: 'Name',
            type: 'text',
            isSearchable: false,
            isSortable: false,
            alignment: 'left',
        },
    ],
    filters: [],
    recordActions: [],
    toolbarActions: [
        { label: 'New post', icon: null, color: null, modal: 'create' },
    ],
    bulkActions: [],
    footerActions: [],
    records: [],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    recordModals: {
        enabled: true,
        token: 'signed-token',
        source: 'server',
        createForm: {
            schema: [{ type: 'text-input', name: 'name', label: 'Name' }],
            data: {},
        },
    },
};

/** A text input marked `autofocus`, standing in for the core text field. */
const AutofocusInput = defineComponent({
    name: 'TextInputField',
    setup: () => () =>
        h('input', { autofocus: true, 'data-test': 'autofocus-field' }),
});

let KinetixTable: Component;

beforeEach(async () => {
    vi.resetModules();
    KinetixTable = (await import('@/components/KinetixTable.vue')).default;
});

afterEach(() => {
    document.body.innerHTML = '';
});

const openCreate = async () => {
    const wrapper = mount(KinetixTable, {
        attachTo: document.body,
        props: { table: table as never },
        global: {
            plugins: [i18n],
            stubs: { TextInputField: AutofocusInput },
        },
    });

    await wrapper
        .findAll('button')
        .find((b) => b.text() === 'New post')!
        .trigger('click');
    // The modal places focus over a few ticks, before the form has arrived.
    for (let tick = 0; tick < 6; tick++) {
        await nextTick();
    }

    const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
    const save = () =>
        dialog.querySelector('button[type="submit"]') as HTMLButtonElement;

    return { wrapper, dialog, save };
};

const formArrived = (dialog: HTMLElement) =>
    vi.waitFor(async () => {
        await flushPromises();
        expect(dialog.querySelector('form')).not.toBeNull();
    });

describe('KinetixTable — the record modal form loads on first open', () => {
    it('keeps Save busy until the form is in the page, then enables it', async () => {
        const { wrapper, dialog, save } = await openCreate();

        // Cold: the block skeleton holds the body, and Save can't submit yet.
        expect(dialog.querySelector('form')).toBeNull();
        expect(
            dialog.querySelector('[data-kinetix-lazy-skeleton="block"]'),
        ).not.toBeNull();
        expect(save().disabled).toBe(true);
        expect(save().getAttribute('aria-busy')).toBe('true');

        await formArrived(dialog);
        await nextTick();

        expect(save().disabled).toBe(false);
        expect(save().hasAttribute('aria-busy')).toBe(false);
        expect(save().getAttribute('form')).toBe(
            dialog.querySelector('form')!.id,
        );
        wrapper.unmount();
    });

    it('hands focus to the autofocus field once the form arrives', async () => {
        const { wrapper, dialog } = await openCreate();

        // Where the modal put focus at open: its first stop, the close button.
        expect(dialog.contains(document.activeElement)).toBe(true);
        expect(document.activeElement?.getAttribute('data-test')).not.toBe(
            'autofocus-field',
        );

        await formArrived(dialog);

        expect(document.activeElement?.getAttribute('data-test')).toBe(
            'autofocus-field',
        );
        wrapper.unmount();
    });

    it('leaves focus alone when the user already moved it', async () => {
        const { wrapper, dialog } = await openCreate();

        const cancel = [...dialog.querySelectorAll('button')].find(
            (b) => b.textContent?.trim() === 'Cancel',
        ) as HTMLButtonElement;
        cancel.focus();

        await formArrived(dialog);

        expect(document.activeElement).toBe(cancel);
        wrapper.unmount();
    });
});
