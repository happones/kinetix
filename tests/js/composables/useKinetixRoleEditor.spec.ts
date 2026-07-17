import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const save = vi.fn();
const remove = vi.fn();

vi.mock('@/composables/useKinetixRoles', () => ({
    useKinetixRoles: () => ({
        features: ref([]),
        roles: ref([]),
        loading: ref(false),
        load,
        save,
        remove,
    }),
}));

const success = vi.fn();
const error = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => success(...args),
        error: (...args: unknown[]) => error(...args),
    },
}));

import { useKinetixRoleEditor } from '@/composables/useKinetixRoleEditor';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
        en: {
            kinetix: {
                saved: 'Saved',
                save_failed: 'Save failed',
                deleted: 'Deleted',
                delete_failed: 'Delete failed',
            },
        },
    },
});

const mountEditor = () => {
    let api: ReturnType<typeof useKinetixRoleEditor>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixRoleEditor();

            return () => h('div');
        },
    });

    mount(Harness, { global: { plugins: [i18n] } });

    return api!;
};

const role = { id: 1, name: 'editor', permissions: [] };

describe('useKinetixRoleEditor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        save.mockResolvedValue({});
        remove.mockResolvedValue({});
    });

    it('loads the catalog on mount', () => {
        mountEditor();
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('saveRole saves, reloads, toasts success and resolves true', async () => {
        const api = mountEditor();

        const ok = await api.saveRole(role);

        expect(save).toHaveBeenCalledWith(role);
        expect(load).toHaveBeenCalledTimes(2); // mount + after save
        expect(success).toHaveBeenCalledWith('Saved');
        expect(ok).toBe(true);
    });

    it('saveRole toasts error and resolves false on failure', async () => {
        save.mockRejectedValueOnce(new Error('nope'));
        const api = mountEditor();

        const ok = await api.saveRole(role);

        expect(error).toHaveBeenCalledWith('Save failed');
        expect(ok).toBe(false);
    });

    it('removeRole removes, reloads, toasts success and resolves true', async () => {
        const api = mountEditor();

        const ok = await api.removeRole(role);

        expect(remove).toHaveBeenCalledWith(role);
        expect(success).toHaveBeenCalledWith('Deleted');
        expect(ok).toBe(true);
    });
});
