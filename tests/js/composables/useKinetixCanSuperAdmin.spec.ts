import { describe, expect, it, vi } from 'vitest';

// A super-admin holds the role, not the individual permissions (server bypasses
// via Gate::before). The composable must mirror that so the SPA never hides UI
// the server would authorize.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            kinetix_permissions: {
                enabled: true,
                permissions: [],
                roles: ['super-admin'],
                isSuperAdmin: true,
            },
        },
    }),
}));

import { useKinetixCan } from '@/composables/useKinetixCan';

describe('useKinetixCan (super-admin)', () => {
    it('grants every permission check despite holding no explicit permissions', () => {
        const { can, canAny, canAll, isSuperAdmin } = useKinetixCan();

        expect(isSuperAdmin.value).toBe(true);
        expect(can('anything.at.all')).toBe(true);
        expect(can('posts.delete')).toBe(true);
        expect(canAny(['x.y'])).toBe(true);
        expect(canAll(['x.y', 'a.b'])).toBe(true);
    });
});
