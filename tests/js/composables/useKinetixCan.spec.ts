import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            kinetix_permissions: {
                enabled: true,
                permissions: ['posts.view', 'posts.update'],
                roles: ['editor'],
            },
        },
    }),
}));

import { useKinetixCan } from '@/composables/useKinetixCan';

describe('useKinetixCan', () => {
    it('checks single permissions', () => {
        const { can } = useKinetixCan();

        expect(can('posts.view')).toBe(true);
        expect(can('posts.delete')).toBe(false);
    });

    it('checks any / all', () => {
        const { canAny, canAll } = useKinetixCan();

        expect(canAny(['posts.delete', 'posts.update'])).toBe(true);
        expect(canAny(['posts.delete'])).toBe(false);
        expect(canAll(['posts.view', 'posts.update'])).toBe(true);
        expect(canAll(['posts.view', 'posts.delete'])).toBe(false);
    });

    it('checks roles (string or array)', () => {
        const { hasRole, roles, enabled } = useKinetixCan();

        expect(hasRole('editor')).toBe(true);
        expect(hasRole('admin')).toBe(false);
        expect(hasRole(['admin', 'editor'])).toBe(true);
        expect(roles.value).toEqual(['editor']);
        expect(enabled.value).toBe(true);
    });
});
