import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), post: vi.fn(), put: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    usePoll: vi.fn(),
}));

import KinetixTable from '@/components/KinetixTable.vue';

/**
 * The simple-resource record modal keeps its actions in the shell's PINNED
 * footer. Regression it guards: Cancel/Save used to render at the bottom of the
 * form itself, so a long schema scrolled them out of reach — and once they moved
 * out of the `<form>`, only native form association can still submit them.
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
                no_records_found: 'No records',
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

describe('KinetixTable — the record modal pins its actions', () => {
    it('renders Cancel/Save in the modal footer, wired to the form by id', async () => {
        const w = mount(KinetixTable, {
            attachTo: document.body,
            props: { table: table as any },
            global: { plugins: [i18n] },
        });

        const create = w
            .findAll('button')
            .find((b) => b.text() === 'New post') as any;
        await create.trigger('click');
        await nextTick();
        await nextTick();

        const dialog = document.body.querySelector(
            '[role="dialog"]',
        ) as HTMLElement;
        expect(dialog).not.toBeNull();

        const form = dialog.querySelector('form') as HTMLFormElement;
        expect(form.id).toMatch(/^kinetix-record-form-/);

        const submit = dialog.querySelector(
            'button[type="submit"]',
        ) as HTMLButtonElement;
        expect(submit.textContent?.trim()).toBe('Save');
        // Outside the <form>, so the native `form` attribute is the only wiring.
        expect(submit.closest('form')).toBeNull();
        expect(submit.getAttribute('form')).toBe(form.id);
        // Pinned: not inside the body's scroller.
        expect(submit.closest('[data-slot="scroll-area-viewport"]')).toBeNull();

        const cancel = [...dialog.querySelectorAll('button')].find(
            (b) => b.textContent?.trim() === 'Cancel',
        ) as HTMLButtonElement;
        expect(cancel).toBeTruthy();
        expect(cancel.closest('form')).toBeNull();

        w.unmount();
        document.body.innerHTML = '';
    });
});
