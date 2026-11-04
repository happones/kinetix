import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type {
    KinetixPermissionState,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * The single permission check shared by `useKinetixCan()` and the `v-can`
 * directive, so the two can never drift. Builds the membership Set once and
 * returns a checker: O(1) per lookup instead of an O(permissions) scan.
 *
 * A super-admin passes every check, mirroring the server-side bypass: they hold
 * the role, not the individual permissions, so without it the SPA would hide UI
 * the server actually authorizes. A missing state (no shared prop, no Inertia
 * page) denies everything and never throws.
 */
export function kinetixPermissionChecker(
    state: Partial<KinetixPermissionState> | null | undefined,
): (permission: string) => boolean {
    if (state?.isSuperAdmin) {
        return () => true;
    }

    const granted = new Set(state?.permissions ?? []);

    return (permission: string): boolean => granted.has(permission);
}

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

    // `page?.props?.` — outside a mounted Inertia app (component tests, SSR
    // edge cases) usePage yields no page; everything then denies, it never throws.
    const state = computed(
        () =>
            page?.props?.kinetix_permissions ?? {
                enabled: false,
                permissions: [] as string[],
                roles: [] as string[],
                isSuperAdmin: false,
            },
    );

    const permissions: ComputedRef<string[]> = computed(
        () => state.value.permissions ?? [],
    );
    const roles: ComputedRef<string[]> = computed(
        () => state.value.roles ?? [],
    );
    const enabled: ComputedRef<boolean> = computed(() => !!state.value.enabled);

    // Mirrors the server-side super-admin bypass (see kinetixPermissionChecker).
    const isSuperAdmin: ComputedRef<boolean> = computed(
        () => !!state.value.isSuperAdmin,
    );

    // Memoized per permission state: the Set is rebuilt only when Inertia
    // replaces the props, not on every gate (a page can render many checks).
    const checker = computed(() => kinetixPermissionChecker(state.value));

    const can = (permission: string): boolean => checker.value(permission);

    const canAny = (perms: string[]): boolean => perms.some((p) => can(p));

    const canAll = (perms: string[]): boolean => perms.every((p) => can(p));

    const hasRole = (role: string | string[]): boolean => {
        const wanted = Array.isArray(role) ? role : [role];

        return wanted.some((r) => roles.value.includes(r));
    };

    return {
        can,
        canAny,
        canAll,
        hasRole,
        permissions,
        roles,
        enabled,
        isSuperAdmin,
    };
}
