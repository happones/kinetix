import { mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn() } }));

import { router } from '@inertiajs/vue3';
import {
    buildTableQuery,
    useKinetixTableQuery,
} from '@/composables/useKinetixTableQuery';

describe('buildTableQuery', () => {
    it('namespaces every reload key with the table prefix', () => {
        const query = buildTableQuery(
            'users_',
            { search: 'ann', page: 2, filters: { role: 'admin' } },
            '',
        );

        expect(query).toEqual({
            users_search: 'ann',
            users_page: 2,
            users_filters: { role: 'admin' },
        });
    });

    it("preserves foreign query params but replaces the table's own", () => {
        const query = buildTableQuery(
            'users_',
            { search: 'new', page: 1 },
            '?users_search=old&users_page=5&posts_page=3&tab=profile',
        );

        // Foreign params survive untouched.
        expect(query.posts_page).toBe('3');
        expect(query.tab).toBe('profile');
        // The table's own params are overwritten with the merged state.
        expect(query.users_search).toBe('new');
        expect(query.users_page).toBe(1);
    });

    it('treats prefixed filter keys as owned and drops the stale ones', () => {
        const query = buildTableQuery(
            'users_',
            { filters: { role: 'admin' } },
            '?users_filters[status]=active&other_filters[x]=1',
        );

        // Stale namespaced filter params are not preserved...
        expect(query['users_filters[status]']).toBeUndefined();
        // ...but the fresh filters object is written, and foreign filters remain.
        expect(query.users_filters).toEqual({ role: 'admin' });
        expect(query['other_filters[x]']).toBe('1');
    });

    it('works with an empty prefix', () => {
        const query = buildTableQuery('', { search: 'x' }, '?keep=1');

        expect(query.search).toBe('x');
        expect(query.keep).toBe('1');
    });
});

describe('buildTableQuery — cursor pagination', () => {
    it('owns the cursor key, so a stale one never survives a reload', () => {
        const query = buildTableQuery(
            'users_',
            { cursor: 'new-cursor' },
            '?users_cursor=stale&other_cursor=keep',
        );

        expect(query.users_cursor).toBe('new-cursor');
        // A cursor belonging to another table on the page is untouched.
        expect(query.other_cursor).toBe('keep');
    });

    it('drops the table cursor entirely when the reload omits it', () => {
        // What a sort/filter change produces: the seek position is meaningless
        // under a new ordering, so it must not linger in the URL.
        const query = buildTableQuery(
            'users_',
            { sort: 'name', direction: 'asc' },
            '?users_cursor=stale',
        );

        expect(query).not.toHaveProperty('users_cursor');
    });
});

describe('useKinetixTableQuery — reloadOnly', () => {
    const table: any = {
        queryPrefix: '',
        state: { search: '', sort: 'id', direction: 'asc', perPage: 10 },
        pagination: { currentPage: 1 },
    };

    const reloadWith = (reloadOnly?: () => string[] | undefined) => {
        let api!: ReturnType<typeof useKinetixTableQuery>;

        mount(
            defineComponent({
                setup() {
                    api = useKinetixTableQuery({
                        table: () => table,
                        searchQuery: ref(''),
                        activeFilters: ref({}),
                        reloadOnly,
                    });

                    return () => null;
                },
            }),
        );
        api.triggerReload({ page: 2 });

        return vi.mocked(router.get).mock.calls.at(-1)![2] as Record<
            string,
            unknown
        >;
    };

    beforeEach(() => vi.mocked(router.get).mockClear());

    it('sends the named props as `only` on a visit', () => {
        expect(reloadWith(() => ['table']).only).toEqual(['table']);
    });

    it('sends no `only` key at all without reloadOnly', () => {
        expect('only' in reloadWith()).toBe(false);
        expect('only' in reloadWith(() => undefined)).toBe(false);
        expect('only' in reloadWith(() => [])).toBe(false);
    });
});
