import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixPermissionFeature,
    KinetixRole,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * CRUD for the role-management UI, talking to Kinetix's permission endpoints.
 * The route prefix (incl. any team segment) comes from the shared `kinetix_config`.
 */
export function useKinetixRoles() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/permissions`;

    const features = ref<KinetixPermissionFeature[]>([]);
    const roles = ref<KinetixRole[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const [loadedFeatures, loadedRoles] = await Promise.all([
                kinetixFetch<KinetixPermissionFeature[]>(`${base()}/features`),
                kinetixFetch<KinetixRole[]>(`${base()}/roles`),
            ]);

            features.value = loadedFeatures ?? [];
            roles.value = loadedRoles ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function save(role: KinetixRole): Promise<unknown> {
        const body: Record<string, unknown> = {
            name: role.name,
            permissions: role.permissions,
        };

        if (role.id) {
            return kinetixFetch(`${base()}/roles/${role.id}`, {
                method: 'PUT',
                body,
            });
        }

        // Super-admin only: create as GLOBAL (team-NULL) — see the editor's toggle.
        if (role.global) {
            body.global = true;
        }

        return kinetixFetch(`${base()}/roles`, { method: 'POST', body });
    }

    async function remove(role: KinetixRole): Promise<unknown> {
        return kinetixFetch(`${base()}/roles/${role.id}`, { method: 'DELETE' });
    }

    return { features, roles, loading, load, save, remove };
}
