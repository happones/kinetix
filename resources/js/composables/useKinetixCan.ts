import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixSharedProps } from '@/types';

/**
 * Frontend authorization mirror of the backend Gate/policies. Reads the
 * `kinetix_permissions` shared prop (provided by Kinetix — no host wiring) so the
 * SPA can gate UI by the same `{feature}.{ability}` keys the server enforces.
 *
 * All checks are reactive: use them in templates / computed and they update when
 * Inertia replaces the page props (e.g. after a role change).
 */
export function useKinetixCan() {
    const page = usePage<KinetixSharedProps>();

    const state = computed(
        () =>
            page.props.kinetix_permissions ?? {
                enabled: false,
                permissions: [] as string[],
                roles: [] as string[],
            },
    );

    const permissions: ComputedRef<string[]> = computed(
        () => state.value.permissions ?? [],
    );
    const roles: ComputedRef<string[]> = computed(
        () => state.value.roles ?? [],
    );
    const enabled: ComputedRef<boolean> = computed(() => !!state.value.enabled);

    const can = (permission: string): boolean =>
        permissions.value.includes(permission);

    const canAny = (perms: string[]): boolean => perms.some((p) => can(p));

    const canAll = (perms: string[]): boolean => perms.every((p) => can(p));

    const hasRole = (role: string | string[]): boolean => {
        const wanted = Array.isArray(role) ? role : [role];

        return wanted.some((r) => roles.value.includes(r));
    };

    return { can, canAny, canAll, hasRole, permissions, roles, enabled };
}
