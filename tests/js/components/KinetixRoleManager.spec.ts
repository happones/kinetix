import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const save = vi.fn().mockResolvedValue({});
const remove = vi.fn().mockResolvedValue({});
const roles = ref<any[]>([]);
const features = ref<any[]>([]);

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

import KinetixRoleManager from '@/components/KinetixRoleManager.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                roles_title: 'Roles',
                create_role: 'New role',
                role_permissions_count: '{count} permissions',
                role_members: '{count} members',
                edit: 'Edit',
                delete: 'Delete',
                cancel: 'Cancel',
                confirm_delete: 'Sure?',
                no_roles: 'No roles',
            },
        },
    },
});

const mountManager = () =>
    mount(KinetixRoleManager, { global: { plugins: [i18n] } });

describe('KinetixRoleManager', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        save.mockResolvedValue({});
        remove.mockResolvedValue({});
        roles.value = [
            {
                id: 1,
                name: 'editor',
                permissions: ['posts.view', 'posts.update'],
                usersCount: 3,
            },
        ];
    });

    it('lists roles with their permission and member counts', () => {
        const w = mountManager();

        expect(w.text()).toContain('editor');
        expect(w.text()).toContain('2 permissions');
        expect(w.text()).toContain('3 members');
    });

    it('deletes a role after confirmation', async () => {
        const w = mountManager();

        // First click reveals the inline confirm; the confirm click removes.
        await w
            .findAll('button')
            .find((b) => b.text() === 'Delete')!
            .trigger('click');
        await w
            .findAll('button')
            .find((b) => b.text() === 'Delete')!
            .trigger('click');

        expect(remove).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1, name: 'editor' }),
        );
    });
});
