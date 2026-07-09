import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const save = vi.fn().mockResolvedValue({});
const remove = vi.fn().mockResolvedValue({});
const features = ref<any[]>([]);
const roles = ref<any[]>([]);

vi.mock('@/composables/useKinetixRoles', () => ({
    useKinetixRoles: () => ({
        features,
        roles,
        loading: ref(false),
        load,
        save,
        remove,
    }),
}));

import KinetixRoleMatrix from '@/components/KinetixRoleMatrix.vue';

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
                role_permissions_count: '{count} permissions',
                role_matrix_hint: 'Toggle permissions per module.',
                role_matrix_module: 'Module',
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
        name: 'users',
        label: 'Users',
        abilities: [
            { key: 'view', label: 'View', permission: 'users.view' },
            { key: 'create', label: 'Create', permission: 'users.create' },
        ],
    },
    {
        name: 'orders',
        label: 'Orders',
        abilities: [
            { key: 'view', label: 'View', permission: 'orders.view' },
            { key: 'refund', label: 'Refund', permission: 'orders.refund' },
        ],
    },
];

const mountMatrix = () =>
    // The reka dialog teleports to <body>, so attach and query the document.
    mount(KinetixRoleMatrix, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

const bodyText = () => document.body.textContent ?? '';

describe('KinetixRoleMatrix', () => {
    beforeEach(() => {
        features.value = CATALOG;
        roles.value = [
            {
                id: 1,
                name: 'editor',
                permissions: ['users.view', 'orders.view'],
                usersCount: 8,
            },
        ];
        save.mockClear();
    });

    it('renders role cards with member and permission counts', () => {
        const w = mountMatrix();

        expect(w.text()).toContain('editor');
        expect(w.text()).toContain('8 members');
        expect(w.text()).toContain('2 permissions');
    });

    it('builds the ability columns as a canonical union with customs appended', async () => {
        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        const headers = [...document.body.querySelectorAll('th')].map((th) =>
            th.textContent?.trim(),
        );
        // Module column, then view/create (canonical order), then the custom refund.
        expect(headers).toEqual(['Module', 'View', 'Create', 'Refund']);

        // Undeclared abilities render as an em-dash placeholder.
        expect(bodyText()).toContain('—');
        w.unmount();
    });

    it('clicking a module name toggles its whole row and saves the union', async () => {
        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        // Toggle the full "Orders" row on (refund was off → adds every ability).
        const orders = [...document.body.querySelectorAll('tbody td')].find(
            (td) => td.textContent?.trim() === 'Orders',
        ) as HTMLElement;
        orders.click();
        await w.vm.$nextTick();

        document.body
            .querySelector<HTMLFormElement>('form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await w.vm.$nextTick();

        expect(save).toHaveBeenCalledWith({
            id: 1,
            name: 'editor',
            permissions: expect.arrayContaining([
                'users.view',
                'orders.view',
                'orders.refund',
            ]),
        });
        w.unmount();
    });
});
