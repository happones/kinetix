import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixRoles } from '@/composables/useKinetixRoles';
import type { KinetixRole } from '@/types';

/**
 * Shared role-management orchestration on top of `useKinetixRoles`: loads the
 * catalog on mount and wraps save/delete with busy flags, success/error toasts
 * and a refetch. Both role UIs — `KinetixRoleManager` (grouped checklists) and
 * `KinetixRoleMatrix` (module × ability grid) — build their own editing/draft
 * presentation on top of this, so the CRUD flow lives in one place instead of
 * being duplicated per component.
 *
 * `save`/`remove` resolve `true` on success so the caller can close its editor
 * or confirmation UI only when the request actually succeeded.
 */
export function useKinetixRoleEditor() {
    const { features, roles, loading, load, save, remove } = useKinetixRoles();
    const { t } = useI18n();

    const saving = ref(false);
    const deleting = ref(false);

    onMounted(load);

    async function saveRole(role: KinetixRole): Promise<boolean> {
        saving.value = true;

        try {
            await save(role);
            await load();
            toast.success(t('kinetix.saved'));

            return true;
        } catch {
            toast.error(t('kinetix.save_failed'));

            return false;
        } finally {
            saving.value = false;
        }
    }

    async function removeRole(role: KinetixRole): Promise<boolean> {
        deleting.value = true;

        try {
            await remove(role);
            await load();
            toast.success(t('kinetix.deleted'));

            return true;
        } catch {
            toast.error(t('kinetix.delete_failed'));

            return false;
        } finally {
            deleting.value = false;
        }
    }

    return {
        features,
        roles,
        loading,
        saving,
        deleting,
        load,
        saveRole,
        removeRole,
    };
}
