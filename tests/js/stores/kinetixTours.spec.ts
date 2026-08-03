import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { pageState, fetchMock } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
    fetchMock: vi.fn().mockResolvedValue({}),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixToursStore } from '@/stores/kinetixTours';

const TOURS = [
    {
        id: 'posts',
        page: 'Kinetix/Posts/Index',
        url: null,
        auto: true,
        steps: [
            {
                selector: '[data-tour=create]',
                title: 'Create',
                description: null,
                side: null,
                align: null,
            },
        ],
    },
    {
        id: 'billing',
        page: null,
        url: '/billing*',
        auto: true,
        steps: [],
    },
    {
        id: 'manual-only',
        page: 'Kinetix/Posts/*',
        url: null,
        auto: false,
        steps: [],
    },
];

const share = (overrides: Record<string, unknown> = {}) => {
    pageState.props = {
        kinetix_tours: {
            enabled: true,
            driver: 'local',
            tours: TOURS,
            seen: [],
            ...overrides,
        },
    };
};

describe('useKinetixToursStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        localStorage.clear();
        share();
    });

    it('matches auto tours by page component and url pattern', () => {
        const store = useKinetixToursStore();

        expect(store.matchFor('Kinetix/Posts/Index', '/posts')?.id).toBe(
            'posts',
        );
        expect(store.matchFor('Billing/Index', '/billing/plans')?.id).toBe(
            'billing',
        );
        expect(store.matchFor('Dashboard', '/dashboard')).toBeNull();
    });

    it('never auto-matches manual-only or seen tours', () => {
        const store = useKinetixToursStore();

        // `manual-only` matches the pattern but has auto=false.
        expect(
            store.matchFor('Kinetix/Posts/Create', '/posts/create'),
        ).toBeNull();

        store.markSeen('posts');
        expect(store.matchFor('Kinetix/Posts/Index', '/posts')).toBeNull();
    });

    it('persists seen state in localStorage for the local driver', () => {
        const store = useKinetixToursStore();

        expect(store.hasSeen('posts')).toBe(false);
        store.markSeen('posts');

        expect(localStorage.getItem('kinetix.tour.posts')).toBe('1');
        expect(store.hasSeen('posts')).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();

        store.reset('posts');
        expect(localStorage.getItem('kinetix.tour.posts')).toBeNull();
        expect(store.hasSeen('posts')).toBe(false);
    });

    it('persists seen state through the endpoints for the database driver', () => {
        share({ driver: 'database', seen: ['billing'] });
        const store = useKinetixToursStore();

        // Server-shared seen list hydrates the state.
        expect(store.hasSeen('billing')).toBe(true);

        store.markSeen('posts');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/tours/posts/seen', {
            method: 'POST',
        });
        expect(store.hasSeen('posts')).toBe(true);

        store.reset('billing');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/tours/billing/seen', {
            method: 'DELETE',
        });
        expect(store.hasSeen('billing')).toBe(false);
    });

    it('start activates only known tours and ignores seen state', () => {
        const store = useKinetixToursStore();
        store.markSeen('posts');

        store.start('posts');
        expect(store.activeTourId).toBe('posts');

        store.stop();
        store.start('nope');
        expect(store.activeTourId).toBeNull();
    });

    it('is inert when the module is disabled', () => {
        share({ enabled: false });
        const store = useKinetixToursStore();

        expect(store.matchFor('Kinetix/Posts/Index', '/posts')).toBeNull();
        store.start('posts');
        expect(store.activeTourId).toBeNull();
    });
});
