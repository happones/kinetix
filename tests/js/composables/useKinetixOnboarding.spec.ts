import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();
const pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixOnboarding } from '@/composables/useKinetixOnboarding';

const state = {
    steps: [],
    completedCount: 1,
    total: 3,
    complete: false,
    dismissed: false,
};

describe('useKinetixOnboarding', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        pageProps.kinetix_onboarding = undefined;
    });

    it('loads the checklist state', async () => {
        fetchMock.mockResolvedValue(state);

        const onboarding = useKinetixOnboarding();
        await onboarding.load();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/onboarding');
        expect(onboarding.state.value?.total).toBe(3);
    });

    it('hydrates from the page payload without a request', async () => {
        pageProps.kinetix_onboarding = state;

        const onboarding = useKinetixOnboarding();

        expect(onboarding.state.value?.total).toBe(3);

        await onboarding.load();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refetches over the payload when forced', async () => {
        pageProps.kinetix_onboarding = state;
        fetchMock.mockResolvedValue({ ...state, completedCount: 3 });

        const onboarding = useKinetixOnboarding();
        await onboarding.load(true);

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/onboarding');
        expect(onboarding.state.value?.completedCount).toBe(3);
    });

    it('lets a completed step win over the stale payload', async () => {
        pageProps.kinetix_onboarding = state;
        fetchMock.mockResolvedValue({ ...state, completedCount: 2 });

        const onboarding = useKinetixOnboarding();
        await onboarding.complete('invite-team');

        expect(onboarding.state.value?.completedCount).toBe(2);
    });

    it('posts the step key on complete and stores the refreshed state', async () => {
        fetchMock.mockResolvedValue({ ...state, completedCount: 2 });

        const onboarding = useKinetixOnboarding();
        await onboarding.complete('invite-team');

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/onboarding/complete',
            {
                method: 'POST',
                body: { step: 'invite-team' },
            },
        );
        expect(onboarding.state.value?.completedCount).toBe(2);
    });

    it('marks dismissed locally after dismissing', async () => {
        fetchMock.mockResolvedValueOnce(state).mockResolvedValueOnce({});

        const onboarding = useKinetixOnboarding();
        await onboarding.load();
        await onboarding.dismiss();

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/onboarding/dismiss',
            {
                method: 'POST',
            },
        );
        expect(onboarding.state.value?.dismissed).toBe(true);
    });

    it('dismisses a payload-hydrated checklist without loading it first', async () => {
        pageProps.kinetix_onboarding = state;
        fetchMock.mockResolvedValue({});

        const onboarding = useKinetixOnboarding();
        await onboarding.dismiss();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(onboarding.state.value?.dismissed).toBe(true);
    });
});
