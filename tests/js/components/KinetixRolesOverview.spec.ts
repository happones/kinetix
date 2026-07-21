import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const save = vi.fn().mockResolvedValue({});
const remove = vi.fn().mockResolvedValue({});
const features = ref<any[]>([]);
const roles = ref<any[]>([]);
const loading = ref(false);

vi.mock('@/composables/useKinetixRoles', () => ({
    useKinetixRoles: () => ({
        features,
        roles,
        loading,
        load,
        save,
        remove,
    }),
}));

import KinetixRolesOverview from '@/components/KinetixRolesOverview.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                roles_title: 'Roles & Permissions',
                create_role: 'New role',
                role_name: 'Role name',
                role_members: '{count} members',
                role_matrix_hint: 'Toggle permissions per module.',
                role_matrix_module: 'Module',
                permission_matrix: 'Permission matrix',
                role_matrix_full: 'All abilities granted',
                role_matrix_partial: '{granted} of {total} abilities',
                role_matrix_none: 'No abilities granted',
                role_more_features: '+{count} more',
                edit: 'Edit',
                delete: 'Delete',
                cancel: 'Cancel',
                save: 'Save',
                saved: 'Saved',
                confirm_delete: 'Are you sure?',
                no_roles: 'No roles yet.',
            },
        },
    },
});

const CATALOG = [
    {
        name: 'patients',
        label: 'Patients',
        abilities: [
            { key: 'view', label: 'View', permission: 'patients.view' },
            { key: 'update', label: 'Update', permission: 'patients.update' },
        ],
    },
    {
        name: 'billing',
        label: 'Billing',
        abilities: [{ key: 'view', label: 'View', permission: 'billing.view' }],
    },
];

const mountOverview = () =>
    // The reka dialog teleports to <body>, so attach and query the document.
    mount(KinetixRolesOverview, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

describe('KinetixRolesOverview', () => {
    beforeEach(() => {
        features.value = CATALOG;
        roles.value = [
            {
                id: 1,
                name: 'admin',
                permissions: [
                    'patients.view',
                    'patients.update',
                    'billing.view',
                ],
                usersCount: 3,
            },
            {
                id: 2,
                name: 'nurse',
                permissions: ['patients.view'],
                usersCount: 12,
            },
        ];
        loading.value = false;
        save.mockClear();
        remove.mockClear();
    });

    it('renders role cards with member counts and granted-module summary', () => {
        const w = mountOverview();

        expect(w.text()).toContain('admin');
        expect(w.text()).toContain('3 members');
        expect(w.text()).toContain('nurse');
        expect(w.text()).toContain('12 members');

        // nurse's card lists Patients (granted) but not Billing (none granted).
        const nurseCard = w
            .findAll('.grid > div')
            .find((card) => card.text().includes('nurse'));
        expect(nurseCard?.text()).toContain('Patients');
        expect(nurseCard?.text()).not.toContain('Billing');
        w.unmount();
    });

    it('renders the read-only matrix with full / partial / none cells', () => {
        roles.value = [
            {
                id: 2,
                name: 'nurse',
                // One of two Patients abilities → partial; none of Billing → none.
                permissions: ['patients.view'],
                usersCount: 12,
            },
        ];
        const w = mountOverview();

        expect(w.text()).toContain('Permission matrix');

        const cells = w.findAll('tbody td');
        // Row "Patients": module cell + nurse cell showing the partial badge.
        expect(cells[1].text()).toContain('1/2');
        expect(cells[1].get('span').attributes('title')).toBe(
            '1 of 2 abilities',
        );
        // Row "Billing": em-dash for none.
        expect(cells[3].text()).toContain('—');
        w.unmount();
    });

    it('shows a check for a role holding every ability of a module', () => {
        const w = mountOverview();

        // admin holds everything → the first data cell carries the "full" title.
        const fullCell = w
            .findAll('tbody td span')
            .find(
                (span) => span.attributes('title') === 'All abilities granted',
            );
        expect(fullCell).toBeTruthy();
        w.unmount();
    });

    it('opens the shared editor modal from a matrix column header', async () => {
        const w = mountOverview();

        const nurseHeader = [
            ...document.body.querySelectorAll('thead th button'),
        ].find((btn) => btn.textContent?.trim() === 'nurse') as HTMLElement;
        nurseHeader.click();
        await w.vm.$nextTick();

        const nameInput = document.body.querySelector<HTMLInputElement>(
            '#kx-role-matrix-name',
        );
        expect(nameInput?.value).toBe('nurse');
        w.unmount();
    });

    it('creates a role through the editor modal', async () => {
        const w = mountOverview();

        await w
            .findAll('button')
            .find((b) => b.text().includes('New role'))!
            .trigger('click');
        await w.vm.$nextTick();

        const nameInput = document.body.querySelector<HTMLInputElement>(
            '#kx-role-matrix-name',
        )!;
        nameInput.value = 'auditor';
        nameInput.dispatchEvent(new Event('input'));
        await w.vm.$nextTick();

        document.body
            .querySelector<HTMLFormElement>('form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await w.vm.$nextTick();

        expect(save).toHaveBeenCalledWith({
            id: null,
            name: 'auditor',
            permissions: [],
        });
        w.unmount();
    });

    it('deletes a role after confirmation', async () => {
        const w = mountOverview();

        await w.get('[title="Delete"]').trigger('click');
        await w.vm.$nextTick();

        const confirmButton = [
            ...document.body.querySelectorAll('button'),
        ].find(
            (btn) =>
                btn.textContent?.trim() === 'Delete' &&
                !btn.hasAttribute('title'),
        ) as HTMLElement;
        confirmButton.click();
        await w.vm.$nextTick();

        expect(remove).toHaveBeenCalledWith(
            expect.objectContaining({ name: 'admin' }),
        );
        w.unmount();
    });

    it('shows the empty state when no roles exist', () => {
        roles.value = [];
        const w = mountOverview();

        expect(w.text()).toContain('No roles yet.');
        expect(w.find('table').exists()).toBe(false);
        w.unmount();
    });
});
