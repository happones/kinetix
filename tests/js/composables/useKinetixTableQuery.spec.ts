import { describe, expect, it } from 'vitest';
import { buildTableQuery } from '@/composables/useKinetixTableQuery';

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
